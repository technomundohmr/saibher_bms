<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\saibher_bms_core\Exception\InsufficientStockException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Turns the lines of a document into stock movements.
 *
 * Nothing else in the project writes the stock: the catalogue, the forms and
 * this service share the field, and only this service moves it, so the
 * movements view always explains the inventory it shows.
 *
 * Every reconciliation is a comparison between what the document currently has
 * stored and what its lines ask for. While the document is a draft the two are
 * kept in sync by rewriting its own movements; once it is closed they are not
 * touched any more, because a movement that already left the warehouse cannot
 * be rewritten — a difference is stored as a new movement instead. That is the
 * hook a returns (devoluciones) screen will use later.
 */
class InventoryMovements implements InventoryMovementsInterface {

  /**
   * Content type the movements are stored in.
   */
  private const MOVEMENT_BUNDLE = 'movement';

  /**
   * Content type of the products whose stock is moved.
   */
  private const PRODUCT_BUNDLE = 'product';

  /**
   * Decimals kept on a quantity, the same as the document lines.
   */
  private const QUANTITY_DECIMALS = 3;

  /**
   * Difference below which two quantities are the same number.
   *
   * Floating point sums of a few decimals can drift, and comparing them with
   * an epsilon avoids storing a pointless movement of zero units.
   */
  private const EPSILON = 0.0005;

  /**
   * Longest reference that fits the movement field.
   */
  private const REFERENCE_LENGTH = 255;

  /**
   * Configuration flag that forbids selling without stock.
   */
  private const SETTING_STRICT_STOCK = 'prohibir_venta_sin_inventario';

  /**
   * Prefix of the lock taken on every product, so two documents sold at the
   * same time are serialised per product.
   */
  private const LOCK_PREFIX = 'saibher_bms_core.inventory.product.';

  /**
   * Seconds a document waits for the stock of another product in use.
   */
  private const LOCK_TIMEOUT = 15.0;

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly LockBackendInterface $lockBackend,
    protected readonly Connection $database,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function reconcile(NodeInterface $document, array $quantities, string $reference): array {
    $documentId = (int) $document->id();
    $desired = $this->aggregate($quantities);

    // The products to lock are read before taking any lock, and the movements
    // are read again once every lock is held: that way the section that writes
    // always works on the latest state. Locks are taken in ascending order so
    // two invoices that share products cannot wait for each other.
    $locked = [];
    try {
      $candidates = array_keys($this->movementsOf($documentId) + $desired);
      sort($candidates);
      foreach ($candidates as $productId) {
        $name = self::LOCK_PREFIX . $productId;
        if ($this->lockBackend->acquire($name, self::LOCK_TIMEOUT)) {
          $locked[] = $name;
          continue;
        }
        throw new ConflictHttpException(sprintf(
          'The inventory of the product %d is being updated by another document.',
          $productId
        ));
      }

      $current = $this->movementsOf($documentId);
      $products = $this->loadProducts($candidates);

      $transaction = $this->database->startTransaction();
      $shortages = $this->isDraft($document)
        ? $this->applyToDraft($document, $reference, $desired, $current, $products)
        : $this->applyToClosedDocument($document, $reference, $desired, $current, $products);
      // La transacción se confirma aquí; si algo lanza antes, el destructor la
      // revierte junto con los movimientos ya escritos.
      $transaction->commitOrRelease();

      return $shortages;
    }
    finally {
      foreach ($locked as $name) {
        $this->lockBackend->release($name);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function stock(NodeInterface $product): float {
    return $this->stockOf($product->id());
  }

  /**
   * {@inheritdoc}
   */
  public function reserved(NodeInterface $document): array {
    $reserved = [];
    foreach ($this->movementsOf((int) $document->id()) as $productId => $movements) {
      $reserved[$productId] = $this->storedQuantity($movements);
    }
    return $reserved;
  }

  /**
   * {@inheritdoc}
   */
  public function releaseDraft(NodeInterface $document): void {
    // Un documento cerrado es historia del inventario: sus movimientos se
    // conservan aunque el documento se borre desde una ruta sin interfaz.
    if (!$this->isDraft($document)) {
      return;
    }

    $movements = $this->movementsOf((int) $document->id());
    if ($movements === []) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $transaction = $this->database->startTransaction();

    foreach ($movements as $productId => $rows) {
      $product = $storage->load((int) $productId);
      if ($product instanceof NodeInterface && $product->hasField('field_stock')) {
        // El borrador Guardó unidades: al devolverlas, el almacén recupera
        // exactamente lo que tenía reservado, y una devolución se descuenta.
        $this->setStock($product, round($this->stockOf($productId) + $this->storedQuantity($rows), self::QUANTITY_DECIMALS));
      }
      foreach ($rows as $movement) {
        $movement->delete();
      }
    }

    $transaction->commitOrRelease();
  }

  /**
   * {@inheritdoc}
   */
  public function blocksUnstockedSales(): bool {
    return (bool) $this->configFactory->get('saibher_bms_core.settings')
      ->get(self::SETTING_STRICT_STOCK);
  }

  /**
   * Rewrites the movements of a draft so they match its lines exactly.
   *
   * One movement per product is kept: raising a line raises its movement,
   * lowering it lowers the movement, and removing the line deletes it and
   * gives the units back. The stock therefore always reflects the draft that is
   * being edited, and nothing depends on how many times the form was saved.
   *
   * @param \Drupal\node\NodeInterface $document
   *   The draft being saved.
   * @param string $reference
   *   Reference stored in the movements.
   * @param array<int, float> $desired
   *   Units of each product, keyed by node ID.
   * @param array<int, \Drupal\node\NodeInterface[]> $current
   *   Movements of the document, grouped by product.
   * @param array<int, \Drupal\node\NodeInterface> $products
   *   The products involved, keyed by node ID.
   *
   * @return array<int, array{name: string, available: float, requested: float}>
   *   Shortages tolerated because the configuration allows them.
   */
  private function applyToDraft(NodeInterface $document, string $reference, array $desired, array $current, array $products): array {
    $changes = [];
    foreach ($desired as $productId => $quantity) {
      $movements = $current[$productId] ?? [];
      $stored = $this->storedQuantity($movements);
      if (abs($stored - $quantity) < self::EPSILON) {
        continue;
      }
      $changes[$productId] = [
        'movements' => $movements,
        'quantity' => $quantity,
        // Unidades que gana o pierde el movimiento. El stock se mueve al revés:
        // más unidades vendidas significan menos existencias.
        'delta' => $quantity - $stored,
      ];
    }
    // Lines the user removed: the movement disappears and the units return.
    foreach ($current as $productId => $movements) {
      if (isset($desired[$productId])) {
        continue;
      }
      $changes[$productId] = [
        'movements' => $movements,
        'quantity' => 0.0,
        'delta' => -$this->storedQuantity($movements),
      ];
    }

    $shortages = $this->guardStock($changes, $desired, $products);

    foreach ($changes as $productId => $change) {
      if (!isset($products[$productId])) {
        continue;
      }
      $product = $products[$productId];
      $available = $this->stockOf($productId);
      $final = round($available - $change['delta'], self::QUANTITY_DECIMALS);

      // El movimiento del borrador resume el documento completo, así que su
      // existencia inicial es la de antes de que el borrador tocara el
      // almacén: el stock guardado ya descuenta las unidades de los
      // autoguardados anteriores.
      $initial = round($available + $this->storedQuantity($change['movements']), self::QUANTITY_DECIMALS);

      // Only the first movement survives: the draft keeps one row per product.
      $movement = $change['movements'][0] ?? NULL;
      foreach (array_slice($change['movements'], 1) as $extra) {
        $extra->delete();
      }

      if ($change['quantity'] < self::EPSILON) {
        $movement?->delete();
      }
      else {
        $movement = $movement ?? $this->newMovement($document, $product, self::TYPE_SALE, $reference);
        $movement->set('field_quantity', $change['quantity']);
        $movement->set('field_initial_quantity', $initial);
        $movement->set('field_final_quantity', $final);
        $movement->save();
      }

      $this->setStock($product, $final);
    }

    return $shortages;
  }

  /**
   * Records the difference between the lines of a closed document and its
   * movements, without rewriting the movements that already exist.
   *
   * More units than the inventory recorded is an extra movement of the same
   * type; fewer units is a return, which is what a dedicated screen for
   * devoluciones will drive once it exists.
   *
   * @param \Drupal\node\NodeInterface $document
   *   The closed document the movements belong to.
   * @param string $reference
   *   Reference stored in the movements.
   * @param array<int, float> $desired
   *   Units of each product, keyed by node ID.
   * @param array<int, \Drupal\node\NodeInterface[]> $current
   *   Movements of the document, grouped by product.
   * @param array<int, \Drupal\node\NodeInterface> $products
   *   The products involved, keyed by node ID.
   *
   * @return array<int, array{name: string, available: float, requested: float}>
   *   Shortages tolerated because the configuration allows them.
   */
  private function applyToClosedDocument(NodeInterface $document, string $reference, array $desired, array $current, array $products): array {
    $changes = [];
    foreach ($desired as $productId => $quantity) {
      $difference = $quantity - $this->storedQuantity($current[$productId] ?? []);
      if (abs($difference) < self::EPSILON) {
        continue;
      }
      $changes[$productId] = [
        'type' => $difference > 0 ? self::TYPE_SALE : self::TYPE_RETURN,
        'quantity' => abs($difference),
        'delta' => $difference,
      ];
    }

    $shortages = $this->guardStock($changes, $desired, $products);

    foreach ($changes as $productId => $change) {
      if (!isset($products[$productId])) {
        continue;
      }
      $product = $products[$productId];
      $available = $this->stockOf($productId);
      $final = round($available - $change['delta'], self::QUANTITY_DECIMALS);

      $movement = $this->newMovement($document, $product, $change['type'], $reference);
      $movement->set('field_quantity', $change['quantity']);
      $movement->set('field_initial_quantity', $available);
      $movement->set('field_final_quantity', $final);
      $movement->save();

      $this->setStock($product, $final);
    }

    return $shortages;
  }

  /**
   * Checks the stock the products are about to have.
   *
   * Every line of the document is judged, not only the ones this save changes:
   * a draft that was saved while the shop allowed selling without inventory
   * still cannot be closed once the flag forbids it, and it is exactly that
   * closing moment when the stock stops being temporary.
   *
   * With the strict flag on a negative result refuses the whole document, so
   * nothing is saved; with the flag off the shortage is only reported, because
   * selling something that is not in the warehouse is a legitimate (if
   * undesirable) decision the shop owner has to be able to take.
   *
   * @param array<int, array{delta: float}> $changes
   *   Planned change of each product, with the signed units its movement takes
   *   (the stock moves the other way around). Products whose line did not move
   *   may be absent: their stock is simply the one they already have.
   * @param array<int, float> $desired
   *   Units the document asks for in total, keyed by product node ID.
   * @param array<int, \Drupal\node\NodeInterface> $products
   *   The products involved, keyed by node ID.
   *
   * @return array<int, array{name: string, available: float, requested: float}>
   *   Shortages tolerated because the configuration allows them.
   *
   * @throws \Drupal\saibher_bms_core\Exception\InsufficientStockException
   *   When the configuration forbids leaving the stock below zero.
   */
  private function guardStock(array $changes, array $desired, array $products): array {
    $shortages = [];
    foreach ($desired as $productId => $quantity) {
      if (!isset($products[$productId])) {
        continue;
      }
      $available = round($this->stockOf($productId), self::QUANTITY_DECIMALS);
      $final = round($available - ($changes[$productId]['delta'] ?? 0.0), self::QUANTITY_DECIMALS);
      if ($final >= -self::EPSILON) {
        continue;
      }
      $shortages[] = [
        'name' => $products[$productId]->label(),
        'available' => $available,
        'requested' => $quantity,
      ];
    }

    if ($shortages !== [] && $this->blocksUnstockedSales()) {
      throw new InsufficientStockException($shortages);
    }

    return $shortages;
  }

  /**
   * Sums the quantities sent by the document, dropping empty lines.
   *
   * @param array<int, float> $quantities
   *   Units of each product, keyed by node ID.
   *
   * @return array<int, float>
   *   The same quantities, summed by product and rounded.
   */
  private function aggregate(array $quantities): array {
    $desired = [];
    foreach ($quantities as $productId => $quantity) {
      $productId = (int) $productId;
      $quantity = round((float) $quantity, self::QUANTITY_DECIMALS);
      if ($productId <= 0 || abs($quantity) < self::EPSILON) {
        continue;
      }
      $desired[$productId] = round(($desired[$productId] ?? 0.0) + $quantity, self::QUANTITY_DECIMALS);
    }
    ksort($desired);
    return $desired;
  }

  /**
   * Movements a document already has, grouped by product and in creation order.
   *
   * @param int $documentId
   *   The document node ID.
   *
   * @return array<int, \Drupal\node\NodeInterface[]>
   *   The movements, keyed by product node ID.
   */
  private function movementsOf(int $documentId): array {
    if ($documentId <= 0) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::MOVEMENT_BUNDLE)
      ->condition('field_movement_document', $documentId)
      ->sort('nid')
      ->execute();

    $movements = [];
    foreach ($storage->loadMultiple($ids) as $movement) {
      if (!$movement instanceof NodeInterface || !$movement->hasField('field_movement_product')) {
        continue;
      }
      $productId = (int) ($movement->get('field_movement_product')->target_id ?? 0);
      if ($productId > 0) {
        $movements[] = $movement;
      }
    }
    return $this->groupByProduct($movements);
  }

  /**
   * Groups movements by the product they belong to.
   *
   * @param array<int, \Drupal\node\NodeInterface> $movements
   *   The movements to group.
   *
   * @return array<int, \Drupal\node\NodeInterface[]>
   *   The movements, keyed by product node ID.
   */
  private function groupByProduct(array $movements): array {
    $grouped = [];
    foreach ($movements as $movement) {
      $productId = (int) ($movement->get('field_movement_product')->target_id ?? 0);
      if ($productId > 0) {
        $grouped[$productId][] = $movement;
      }
    }
    ksort($grouped);
    return $grouped;
  }

  /**
   * Loads the products of a set of node IDs.
   *
   * @param array<int, int> $ids
   *   Product node IDs.
   *
   * @return array<int, \Drupal\node\NodeInterface>
   *   The products that exist, keyed by node ID.
   */
  private function loadProducts(array $ids): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $products = [];
    foreach ($storage->loadMultiple($ids) as $product) {
      if ($product instanceof NodeInterface && $product->bundle() === self::PRODUCT_BUNDLE) {
        $products[(int) $product->id()] = $product;
      }
    }
    return $products;
  }

  /**
   * Units a document took out of the warehouse for a product.
   *
   * Sales add up and returns are taken back, so the result is what the document
   * still holds: a document that sold 10 and returned 6 holds 4.
   *
   * @param array<int, \Drupal\node\NodeInterface> $movements
   *   Movements of a single product.
   *
   * @return float
   *   The net units the document is holding.
   */
  private function storedQuantity(array $movements): float {
    $total = 0.0;
    foreach ($movements as $movement) {
      $quantity = (float) ($movement->get('field_quantity')->value ?? 0.0);
      // Una devolución devuelve unidades al almacén: cuenta al revés, o una
      // segunda devolución mediría la diferencia contra unidades ya devueltas.
      $total += $movement->get('field_movement_type')->value === self::TYPE_RETURN
        ? -$quantity
        : $quantity;
    }
    return round($total, self::QUANTITY_DECIMALS);
  }

  /**
   * Builds the movement that records a change, without saving it.
   *
   * A movement is named after the product so the administration reads like the
   * catalogue, keeps the SKU in field_product_code for the product history and
   * points at the document that caused it, which is what makes the movements
   * view a traceability report.
   *
   * @param \Drupal\node\NodeInterface|null $document
   *   The document to point at, or NULL to read it from the movement that is
   *   being extended.
   * @param \Drupal\node\NodeInterface $product
   *   The product that moved.
   * @param string $type
   *   Value stored in field_movement_type.
   * @param string $reference
   *   Reference stored in field_reference.
   *
   * @return \Drupal\node\NodeInterface
   *   The movement, ready to be completed and saved.
   */
  private function newMovement(?NodeInterface $document, NodeInterface $product, string $type, string $reference): NodeInterface {
    $movement = Node::create([
      'type' => self::MOVEMENT_BUNDLE,
      'title' => $product->label(),
      'field_movement_type' => $type,
      'field_product' => $product->label(),
      'field_product_code' => (string) ($product->get('field_sku')->value ?? ''),
      'field_reference' => mb_substr($reference, 0, self::REFERENCE_LENGTH),
      'field_movement_product' => $product->id(),
      'status' => 1,
    ]);
    if ($document !== NULL) {
      $movement->set('field_movement_document', $document->id());
    }
    return $movement;
  }

  /**
   * Whether a document is still editable, that is, still a draft.
   */
  private function isDraft(NodeInterface $document): bool {
    return !$document->hasField('field_document_status')
      || $document->get('field_document_status')->value === DocumentDraftInterface::STATUS_DRAFT;
  }

  /**
   * Units a product has in the inventory.
   *
   * @param int|string|null $productId
   *   The product node.
   *
   * @return float
   *   The stock stored on the product.
   */
  private function stockOf($productId): float {
    $product = $this->entityTypeManager->getStorage('node')->load((int) $productId);
    if (!$product instanceof NodeInterface || !$product->hasField('field_stock')) {
      return 0.0;
    }
    return round((float) ($product->get('field_stock')->value ?? 0.0), self::QUANTITY_DECIMALS);
  }

  /**
   * Stores the stock of a product.
   *
   * @param \Drupal\node\NodeInterface $product
   *   The product to update.
   * @param float $stock
   *   The units it has.
   */
  private function setStock(NodeInterface $product, float $stock): void {
    if (!$product->hasField('field_stock')) {
      return;
    }
    $product->set('field_stock', $stock);
    $product->save();
  }

}
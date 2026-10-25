<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Turns the billing form state into a `document` node.
 *
 * This is the only place that writes a document, its detail lines, its payments
 * and the inventory movements the lines cause. The amount stored in the
 * database is always the one calculated here from the products, never the one
 * the browser sends, so what the cashier sees and what stays saved cannot drift
 * apart.
 */
class DocumentDraft implements DocumentDraftInterface {

  /**
   * Content type the documents are stored in.
   */
  private const BUNDLE = 'document';

  /**
   * Paragraph type of a document line.
   */
  private const ITEM_BUNDLE = 'document_item';

  /**
   * Paragraph type of a document payment.
   */
  private const PAYMENT_BUNDLE = 'payment';

  /**
   * Content type of the products the lines point at.
   */
  private const PRODUCT_BUNDLE = 'product';

  /**
   * Vocabulary that holds the payment methods.
   */
  private const VOCABULARY_PAYMENT = 'payment_method';

  /**
   * Decimals kept on every stored amount.
   */
  private const DECIMALS = 2;

  /**
   * Decimals kept on a quantity, the same as the inventory movements.
   */
  private const QUANTITY_DECIMALS = 3;

  /**
   * Difference below which a payment is taken as covering the total.
   */
  private const EPSILON = 0.0005;

  /**
   * Longest reference that fits the payment paragraph field.
   */
  private const REFERENCE_LENGTH = 255;

  /**
   * Value stored in field_document_type_document for each series.
   *
   * @var array<string, string>
   */
  private const DOCUMENT_TYPES = [
    'venta' => 'Factura de venta',
    'compra' => 'Factura de compra',
    'ticket' => 'Ticket',
  ];

  /**
   * Series whose lines take units out of the inventory.
   *
   * @var string[]
   */
  private const STOCKED_KINDS = ['venta'];

  /**
   * Series that leave a financial record when they are closed.
   *
   * @var string[]
   */
  private const SELLING_KINDS = ['venta', 'ticket'];

  /**
   * Human labels of every document status, keyed by machine key.
   *
   * @var array<string, string>
   */
  private const STATUS_LABELS = [
    'borrador' => 'Borrador',
    'pendiente_pago' => 'Lista, pendiente de pago',
    'pagada' => 'Pagada',
    'cerrada' => 'Cerrada',
  ];

  /**
   * Builds the draft service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DocumentNumberingInterface $numbering,
    private readonly ProductPricingInterface $pricing,
    private readonly InventoryMovementsInterface $inventory,
    private readonly FinancialRegisterInterface $financialRegister,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly Connection $database,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function save(string $kind, array $payload): array {
    return $this->persist($kind, $payload, FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function finalize(string $kind, array $payload): array {
    return $this->persist($kind, $payload, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function statusLabels(): array {
    return self::STATUS_LABELS;
  }

  /**
   * Writes the state to the database, as a draft or as a closed document.
   *
   * Everything runs inside one transaction: the document, its lines, its
   * payments and the inventory movements. If a line leaves the warehouse empty
   * and the configuration forbids it, the exception rolls the whole call back
   * and nothing of it stays written.
   *
   * @param string $kind
   *   Series of the document: `venta`, `compra` or `ticket`.
   * @param array $payload
   *   Normalised state sent by the form.
   * @param bool $finalize
   *   TRUE to close the document once it is written, FALSE to leave it a draft.
   *
   * @return array
   *   The saved state, ready for the browser.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   When the requested node is not an editable draft of the current user.
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When the document cannot be closed.
   * @throws \Drupal\saibher_bms_core\Exception\InsufficientStockException
   *   When the configuration forbids saving a document the inventory cannot
   *   cover.
   */
  private function persist(string $kind, array $payload, bool $finalize): array {
    $this->assertKind($kind);

    $items = is_array($payload['items'] ?? NULL) ? $payload['items'] : [];
    $rawPayments = is_array($payload['payments'] ?? NULL) ? $payload['payments'] : [];

    $lines = $this->buildLines($items);
    $partyId = (int) ($payload['partyId'] ?? 0);
    $party = $this->loadParty($partyId);
    $payments = $this->normalisePayments($rawPayments);

    $subtotal = 0.0;
    $taxTotal = 0.0;
    foreach ($lines as $line) {
      $subtotal += $line['total'];
      $taxTotal += $line['tax_total'];
    }
    $subtotal = round($subtotal, self::DECIMALS);
    $taxTotal = round($taxTotal, self::DECIMALS);
    $total = round($subtotal + $taxTotal, self::DECIMALS);

    // A document is closed only with products and a party: the browser is told
    // what is missing, in the language the service already reasons in.
    if ($finalize) {
      if ($lines === []) {
        throw new BadRequestHttpException('A document without products cannot be closed.');
      }
      if ($partyId <= 0) {
        throw new BadRequestHttpException('A document without a party cannot be closed.');
      }
      if ($party === NULL) {
        throw new BadRequestHttpException('The party of the document no longer exists.');
      }
    }

    $transaction = $this->database->startTransaction();
    $document = NULL;
    $documentId = 0;
    try {
      $document = $this->resolveDocument($kind, $payload);
      $documentId = (int) $document->id();

      $document->set('title', self::DOCUMENT_TYPES[$kind]);
      $document->set('field_document_type_document', self::DOCUMENT_TYPES[$kind]);
      $document->set(
        'field_document_prefix',
        ($prefixId = (int) ($payload['prefixId'] ?? 0)) > 0 ? $prefixId : NULL
      );
      $document->set('field_customer_document', $party?->id());
      $document->set('field_subtotal', $subtotal);
      $document->set('field_total_tax', $taxTotal);
      $document->set('field_total', $total);
      $document->set('field_document_status', self::STATUS_DRAFT);
      $document->set('title', $this->title($document, $kind));
      // The document is saved once before its paragraphs so they can be owned:
      // an orphan paragraph is still rendered by its administrative label.
      $document->save();

      $this->syncItems($document, $lines);
      $this->syncPayments($document, $payments);
      $document->save();

      // The lines move the inventory while the document is still a draft, so a
      // refusal leaves nothing written and a line removed gives its units back.
      $shortages = [];
      if (in_array($kind, self::STOCKED_KINDS, TRUE)) {
        $shortages = $this->inventory->reconcile($document, $this->quantities($lines), $this->code($document));
      }

      $financial = NULL;
      if ($finalize) {
        $status = $this->finalStatus($this->paid($payments), $total);
        $document->set('field_document_status', $status);
        $document->save();

        if (in_array($kind, self::SELLING_KINDS, TRUE)) {
          $this->financialRegister->recordSale($document);
          $financial = $this->financialState($document);
          if ($status === 'pendiente_pago') {
            $this->createDebtor($document);
          }
        }
      }

      $transaction->commitOrRelease();

      $state = $this->state($document, $kind, $lines, $shortages);
      if ($financial !== NULL) {
        $state['financial'] = $financial;
      }

      return $state;
    }
    catch (\Throwable $exception) {
      // The transaction destructor rolls the writes back; the entity cache has
      // to forget the node as well so the next save does not read a ghost.
      if ($documentId > 0) {
        $this->entityTypeManager->getStorage('node')->resetCache([$documentId]);
      }
      throw $exception;
    }
  }

  /**
   * Loads the draft to update or builds the node of a new document.
   *
   * The number is reserved only when the node is created: opening an existing
   * draft never asks for a new consecutive.
   *
   * @param string $kind
   *   Series of the document.
   * @param array $payload
   *   Normalised state sent by the form.
   *
   * @return \Drupal\node\NodeInterface
   *   The document, saved or still to save.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   When the node is not an editable draft of the current user.
   */
  private function resolveDocument(string $kind, array $payload): NodeInterface {
    $id = (int) ($payload['documentId'] ?? 0);
    $storage = $this->entityTypeManager->getStorage('node');

    if ($id <= 0) {
      return Node::create([
        'type' => self::BUNDLE,
        'title' => self::DOCUMENT_TYPES[$kind],
        'uid' => \Drupal::currentUser()->id(),
        'status' => 1,
        'field_document_number' => $this->numbering->next($kind),
        'field_document_type_document' => self::DOCUMENT_TYPES[$kind],
        'field_document_status' => self::STATUS_DRAFT,
      ]);
    }

    $node = $storage->load($id);
    if (!$node instanceof NodeInterface
      || $node->bundle() !== self::BUNDLE
      || $node->get('field_document_status')->value !== self::STATUS_DRAFT) {
      throw new AccessDeniedHttpException('The document is not an editable draft.');
    }
    if ((int) $node->getOwnerId() !== (int) \Drupal::currentUser()->id() && !$node->access('update')) {
      throw new AccessDeniedHttpException('The draft belongs to another user.');
    }

    return $node;
  }

  /**
   * Splits the lines sent by the form into stored, calculated lines.
   *
   * The unit price is the one agreed on the counter, and the tax rule is the
   * one of the product, so the browser can change a price but never invent a
   * rate.
   *
   * @param array $items
   *   Items as sent by the form.
   *
   * @return array<int, array{product_id: int, name: string, quantity: float, unit_value: float, tax_pct: int, tax_unit: float, tax_total: float, total: float}>
   *   The calculated lines, empty lines dropped.
   */
  private function buildLines(array $items): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $lines = [];

    foreach ($items as $row) {
      if (!is_array($row)) {
        continue;
      }
      $quantity = round((float) ($row['quantity'] ?? 0.0), self::QUANTITY_DECIMALS);
      if ($quantity <= 0) {
        continue;
      }

      $productId = (int) ($row['product'] ?? 0);
      $product = $productId > 0 ? $storage->load($productId) : NULL;
      if (!$product instanceof NodeInterface || $product->bundle() !== self::PRODUCT_BUNDLE) {
        $product = NULL;
      }

      $unitValue = round((float) ($row['unitValue'] ?? ($product === NULL ? 0.0 : $this->pricing->price($product))), self::DECIMALS);
      $unitValue = max(0.0, $unitValue);
      $taxPct = $product === NULL ? 0 : $this->pricing->taxPct($product);
      $taxUnit = round($unitValue * $taxPct / 100, self::DECIMALS);
      $lineTax = round($taxUnit * $quantity, self::DECIMALS);

      $lines[] = [
        'product_id' => $product === NULL ? 0 : (int) $product->id(),
        'name' => $product === NULL ? '' : $product->label(),
        'quantity' => $quantity,
        'unit_value' => $unitValue,
        'tax_pct' => $taxPct,
        'tax_unit' => $taxUnit,
        'tax_total' => $lineTax,
        'total' => round($unitValue * $quantity, self::DECIMALS),
      ];
    }

    return $lines;
  }

  /**
   * Resolves the party of the document, when it still exists.
   *
   * @param int $partyId
   *   Node ID of the customer or supplier, or 0 when none is chosen.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The party, or NULL when there is none.
   */
  private function loadParty(int $partyId): ?NodeInterface {
    if ($partyId <= 0) {
      return NULL;
    }
    $node = $this->entityTypeManager->getStorage('node')->load($partyId);

    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Turns the payments of the form into resolvable stored payments.
   *
   * The method is matched by exact label against the vocabulary: a wallet that
   * is not declared is saved without a term instead of silently dropped.
   *
   * @param array $payments
   *   Payments as sent by the form.
   *
   * @return array<int, array{method_id: int|null, value: float, reference: string}>
   *   The payments to store, empty ones dropped.
   */
  private function normalisePayments(array $payments): array {
    $terms = $this->paymentTerms();
    $normalised = [];

    foreach ($payments as $row) {
      if (!is_array($row)) {
        continue;
      }
      $value = round((float) ($row['value'] ?? 0.0), self::DECIMALS);
      if ($value <= 0) {
        continue;
      }
      $label = mb_strtolower(trim((string) ($row['method'] ?? '')));
      $normalised[] = [
        'method_id' => $terms[$label] ?? NULL,
        'value' => $value,
        'reference' => mb_substr(trim((string) ($row['reference'] ?? '')), 0, self::REFERENCE_LENGTH),
      ];
    }

    return $normalised;
  }

  /**
   * Payment methods of the vocabulary, keyed by lowercase label.
   *
   * @return array<string, int>
   *   Term ID of each declared method.
   */
  private function paymentTerms(): array {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => self::VOCABULARY_PAYMENT]);
    $byLabel = [];
    foreach ($terms as $term) {
      if ($term instanceof TermInterface) {
        $byLabel[mb_strtolower(trim($term->label()))] = (int) $term->id();
      }
    }

    return $byLabel;
  }

  /**
   * Sincroniza las líneas del documento con lo que pide el formulario.
   *
   * Reordena y reescribe por posición: el párrafo que ya existía se actualiza y
   * el que sobra se borra, así el detalle no acumula huérfanos.
   *
   * @param \Drupal\node\NodeInterface $document
   *   Document being saved.
   * @param array $lines
   *   Lines calculated by the service.
   */
  private function syncItems(NodeInterface $document, array $lines): void {
    $existing = array_values($document->get('field_document_item')->referencedEntities());
    $targets = [];

    foreach ($lines as $index => $line) {
      $paragraph = $existing[$index] ?? Paragraph::create(['type' => self::ITEM_BUNDLE]);
      if (!$paragraph instanceof ParagraphInterface) {
        continue;
      }
      $paragraph->set('field_product', $line['name']);
      if ($paragraph->hasField('field_product_node')) {
        $paragraph->set('field_product_node', $line['product_id'] > 0 ? $line['product_id'] : NULL);
      }
      $paragraph->set('field_quantity', $line['quantity']);
      $paragraph->set('field_unit_value', $line['unit_value']);
      $paragraph->set('field_tax_percentage', $line['tax_pct']);
      $paragraph->set('field_tax_unit_value', $line['tax_unit']);
      $paragraph->set('field_total_tax', $line['tax_total']);
      $paragraph->set('field_total', $line['total']);
      $this->ownParagraph($paragraph, $document, 'field_document_item');
      $paragraph->save();
      $targets[] = ['target_id' => $paragraph->id()];
    }

    foreach (array_slice($existing, count($lines)) as $orphan) {
      $orphan->delete();
    }
    $document->set('field_document_item', $targets);
  }

  /**
   * Sincroniza los pagos del documento con lo que pide el formulario.
   *
   * @param \Drupal\node\NodeInterface $document
   *   Document being saved.
   * @param array $payments
   *   Payments calculated by the service.
   */
  private function syncPayments(NodeInterface $document, array $payments): void {
    $existing = array_values($document->get('field_payment_document')->referencedEntities());
    $targets = [];

    foreach ($payments as $index => $payment) {
      $paragraph = $existing[$index] ?? Paragraph::create(['type' => self::PAYMENT_BUNDLE]);
      if (!$paragraph instanceof ParagraphInterface) {
        continue;
      }
      $paragraph->set('field_method', $payment['method_id']);
      $paragraph->set('field_quantity', $payment['value']);
      $paragraph->set('field_reference', $payment['reference']);
      $this->ownParagraph($paragraph, $document, 'field_payment_document');
      $paragraph->save();
      $targets[] = ['target_id' => $paragraph->id()];
    }

    foreach (array_slice($existing, count($payments)) as $orphan) {
      $orphan->delete();
    }
    $document->set('field_payment_document', $targets);
  }

  /**
   * Writes the owner of a paragraph from the document that holds it.
   *
   * `field_document_item` and `field_payment_document` are plain entity
   * references, so Drupal does not fill the parent by itself; without this the
   * paragraph is shown as "Orphaned Item de documento".
   *
   * @param \Drupal\paragraphs\ParagraphInterface $paragraph
   *   Paragraph to own.
   * @param \Drupal\node\NodeInterface $document
   *   Document that references it.
   * @param string $field_name
   *   Field of the document that holds the paragraph.
   */
  private function ownParagraph(ParagraphInterface $paragraph, NodeInterface $document, string $field_name): void {
    $paragraph->set('parent_type', 'node');
    $paragraph->set('parent_field_name', $field_name);
    $paragraph->set('parent_id', $document->id());
  }

  /**
   * Sums the units of each product, keyed by product node ID.
   *
   * @param array $lines
   *   Lines calculated by the service.
   *
   * @return array<int, float>
   *   Units per product, the input the inventory service understands.
   */
  private function quantities(array $lines): array {
    $quantities = [];
    foreach ($lines as $line) {
      if ($line['product_id'] > 0) {
        $quantities[$line['product_id']] = round(($quantities[$line['product_id']] ?? 0.0) + $line['quantity'], self::QUANTITY_DECIMALS);
      }
    }

    return $quantities;
  }

  /**
   * Money the payments already cover.
   *
   * @param array $payments
   *   Payments calculated by the service.
   *
   * @return float
   *   The amount paid.
   */
  private function paid(array $payments): float {
    $paid = 0.0;
    foreach ($payments as $payment) {
      $paid += $payment['value'];
    }

    return round($paid, self::DECIMALS);
  }

  /**
   * Status a document is closed with, according to what was paid.
   *
   * @param float $paid
   *   Money the payments cover.
   * @param float $total
   *   Total of the document.
   *
   * @return string
   *   `pagada` when the payments cover the total, `pendiente_pago` otherwise.
   */
  private function finalStatus(float $paid, float $total): string {
    return ($total > 0 && $paid + self::EPSILON >= $total)
      ? 'pagada'
      : 'pendiente_pago';
  }

  /**
   * Financial figures of a closed sale, for the closing response.
   *
   * @param \Drupal\node\NodeInterface $document
   *   The closed document.
   *
   * @return array{income: float, taxes: float, netIncome: float, profit: float}
   *   The four figures of the sale.
   */
  private function financialState(NodeInterface $document): array {
    return [
      'income' => $this->financialRegister->income($document),
      'taxes' => $this->financialRegister->taxes($document),
      'netIncome' => round((float) ($document->get('field_subtotal')->value ?? 0.0), self::DECIMALS),
      'profit' => $this->financialRegister->profit($document),
    ];
  }

  /**
   * State of the saved document, ready for the browser.
   *
   * @param \Drupal\node\NodeInterface $document
   *   The saved document.
   * @param string $kind
   *   Series of the document.
   * @param array $lines
   *   Lines calculated by the service.
   * @param array $shortages
   *   Products the configuration allowed to run out.
   *
   * @return array
   *   The response body the form consumes.
   */
  private function state(NodeInterface $document, string $kind, array $lines, array $shortages): array {
    $status = (string) $document->get('field_document_status')->value;
    $stock = [];
    $reserved = [];

    if (in_array($kind, self::STOCKED_KINDS, TRUE)) {
      $storage = $this->entityTypeManager->getStorage('node');
      foreach ($lines as $line) {
        if ($line['product_id'] <= 0) {
          continue;
        }
        $product = $storage->load($line['product_id']);
        if ($product instanceof NodeInterface) {
          $stock[$line['product_id']] = $this->inventory->stock($product);
        }
      }
      $reserved = $this->inventory->reserved($document);
    }

    return [
      'id' => (int) $document->id(),
      'number' => (int) ($document->get('field_document_number')->value ?? 0),
      'code' => $this->code($document),
      'status' => $status,
      'statusLabel' => self::STATUS_LABELS[$status] ?? $status,
      'subtotal' => (float) $document->get('field_subtotal')->value,
      'taxTotal' => (float) $document->get('field_total_tax')->value,
      'total' => (float) $document->get('field_total')->value,
      'items' => $lines,
      'stock' => $stock,
      'reserved' => $reserved,
      'stockWarnings' => $shortages,
    ];
  }

  /**
   * Human code of a document, for example FV-00002.
   *
   * @param \Drupal\node\NodeInterface $document
   *   The document.
   *
   * @return string
   *   The code, or an empty string when it has no prefix or number.
   */
  private function code(NodeInterface $document): string {
    $number = (int) ($document->get('field_document_number')->value ?? 0);
    if ($number <= 0) {
      return '';
    }

    $prefix = $document->get('field_document_prefix')->entity;
    $code = $prefix instanceof TermInterface ? explode(' - ', $prefix->label())[0] : '';
    if ($code === '') {
      return '';
    }

    return $code . '-' . str_pad((string) $number, 5, '0', STR_PAD_LEFT);
  }

  /**
   * Title of the document: its code, or its type when it has no prefix.
   *
   * @param \Drupal\node\NodeInterface $document
   *   The document.
   * @param string $kind
   *   Series of the document.
   *
   * @return string
   *   The title to store.
   */
  private function title(NodeInterface $document, string $kind): string {
    $code = $this->code($document);

    return $code !== '' ? $code : self::DOCUMENT_TYPES[$kind];
  }

  /**
   * Rejects the kinds that have no document type declared.
   *
   * @param string $kind
   *   Series of the document.
   *
   * @throws \InvalidArgumentException
   *   When the kind cannot be stored.
   */
  private function assertKind(string $kind): void {
    if (!array_key_exists($kind, self::DOCUMENT_TYPES)) {
      throw new \InvalidArgumentException(sprintf('"%s" no es una serie de documento.', $kind));
    }
  }

  /**
   * Leaves a receivable behind for a document that was not paid in full.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The closed document.
   */
  private function createDebtor(NodeInterface $node): void {
    $customer = $node->get('field_customer_document')->entity;
    if (!$customer instanceof NodeInterface) {
      return;
    }
    $total = (float) ($node->get('field_total')->value ?? 0.0);
    if ($total <= 0) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $code = $this->code($node);
    if ($code === '' && $node->get('field_document_number')->value) {
      $code = 'FV-' . str_pad((string) $node->get('field_document_number')->value, 5, '0', STR_PAD_LEFT);
    }
    $debtor = $storage->create([
      'type' => 'debtors',
      'title' => $code . ' · ' . $customer->label(),
      'field_customer_debtors' => ['target_id' => $customer->id()],
      'field_value' => round($total, self::DECIMALS),
      'field_reference' => $code,
    ]);
    $debtor->save();
  }

}

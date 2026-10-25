<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Writes the financial records of a transaction.
 *
 * The register is where the business sees money: what entered, what taxes were
 * collected, what the sale is worth without those taxes and what it left as
 * profit. Every record points at the document that produced it, so the numbers
 * on the register can always be traced back to the invoice they come from.
 */
class FinancialRegister implements FinancialRegisterInterface {

  /**
   * Bundle holding the financial records.
   */
  private const BUNDLE = 'register';

  /**
   * Field holding the document that caused a record.
   */
  private const DOCUMENT_FIELD = 'field_register_document';

  /**
   * Content type of the products.
   */
  private const PRODUCT_BUNDLE = 'product';

  /**
   * Number of decimals kept on every stored amount.
   */
  private const DECIMALS = 2;

  /**
   * Entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Builds the service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function recordSale(NodeInterface $document): array {
    $existing = $this->recordsOf($document);
    if ($existing !== []) {
      return $existing;
    }

    $code = $this->code($document);
    $subtotal = $this->netIncome($document);
    $records = [];

    // El dinero del cliente entra completo, y ese total se separa en lo que es
    // venta y lo que es impuesto recolhido, porque no son lo mismo.
    foreach ([
      static::TYPE_INCOME => $this->income($document),
      static::TYPE_TAXES => $this->taxes($document),
      static::TYPE_NET_INCOME => $subtotal,
    ] as $type => $value) {
      $records[] = $this->create($code, $type, $value, $document);
    }

    $records[] = $this->create($code, static::TYPE_PROFIT, $this->profit($document), $document);

    return $records;
  }

  /**
   * {@inheritdoc}
   */
  public function income(NodeInterface $document): float {
    return $this->amount($document, 'field_total');
  }

  /**
   * {@inheritdoc}
   */
  public function taxes(NodeInterface $document): float {
    return $this->amount($document, 'field_total_tax');
  }

  /**
   * {@inheritdoc}
   */
  public function profit(NodeInterface $document): float {
    return round($this->netIncome($document) - $this->cost($document), self::DECIMALS);
  }

  /**
   * Money a sale adds without the taxes collected.
   *
   * @param \Drupal\node\NodeInterface $document
   *   The closed document.
   *
   * @return float
   *   The subtotal of the document.
   */
  private function netIncome(NodeInterface $document): float {
    return $this->amount($document, 'field_subtotal');
  }

  /**
   * What the merchandise of a document cost.
   *
   * Each line is costed with the product it sold, so the answer belongs to the
   * sale and not to the current price list.
   *
   * @param \Drupal\node\NodeInterface $document
   *   The closed document.
   *
   * @return float
   *   The cost of the sold units.
   */
  private function cost(NodeInterface $document): float {
    $cost = 0.0;

    foreach ($document->get('field_document_item')->referencedEntities() as $paragraph) {
      $quantity = (float) ($paragraph->get('field_quantity')->value ?? 0.0);
      $product = $this->productOf($paragraph);
      if ($quantity <= 0.0 || !$product instanceof NodeInterface) {
        continue;
      }
      $cost += $quantity * (float) ($product->get('field_cost')->value ?? 0.0);
    }

    return round($cost, self::DECIMALS);
  }

  /**
   * Product sold by a document line.
   *
   * @param \Drupal\paragraphs\ParagraphInterface $paragraph
   *   The document item paragraph.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The product, or NULL when it no longer exists.
   */
  private function productOf(ParagraphInterface $paragraph): ?NodeInterface {
    $product = $paragraph->get('field_product_node')->entity;
    if ($product instanceof NodeInterface) {
      return $product;
    }

    // Las líneas anteriores a la referencia se resuelven por nombre, para que el
    // costo no se pierda en documentos ya escritos.
    $name = trim((string) ($paragraph->get('field_product')->value ?? ''));
    if ($name === '') {
      return NULL;
    }

    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::PRODUCT_BUNDLE)
      ->condition('title', $name)
      ->execute();

    foreach ($ids as $id) {
      $product = $this->entityTypeManager->getStorage('node')->load($id);
      if ($product instanceof NodeInterface) {
        return $product;
      }
    }

    return NULL;
  }

  /**
   * Amount stored in a field of the document.
   *
   * @param \Drupal\node\NodeInterface $document
   *   The closed document.
   * @param string $field_name
   *   Field holding the amount.
   *
   * @return float
   *   The amount, rounded to the stored decimals.
   */
  private function amount(NodeInterface $document, string $field_name): float {
    if (!$document->hasField($field_name)) {
      return 0.0;
    }

    return round((float) ($document->get($field_name)->value ?? 0.0), self::DECIMALS);
  }

  /**
   * Writes one record of the register.
   *
   * @param string $code
   *   Human code of the document, for example FV-00002.
   * @param string $type
   *   Series of the record.
   * @param float $value
   *   Amount the record states.
   * @param \Drupal\node\NodeInterface $document
   *   The document that caused the record.
   *
   * @return \Drupal\node\NodeInterface
   *   The record written.
   */
  private function create(string $code, string $type, float $value, NodeInterface $document): NodeInterface {
    /** @var \Drupal\node\NodeInterface $record */
    $record = Node::create([
      'type' => self::BUNDLE,
      'title' => sprintf('%s · %s', $code, $type),
      'field_register_type' => $type,
      'field_value' => round($value, self::DECIMALS),
      'field_reference' => $code,
    ]);
    // El documento deja el número trazable en el registro: cada cifra se puede
    // seguir hasta la factura que la produjo.
    if ($record->hasField(self::DOCUMENT_FIELD)) {
      $record->set(self::DOCUMENT_FIELD, $document->id());
    }
    $record->save();

    return $record;
  }

  /**
   * Records a document already wrote.
   *
   * @param \Drupal\node\NodeInterface $document
   *   The document to look for.
   *
   * @return array<int, \Drupal\node\NodeInterface>
   *   Its records, or an empty list when it has none.
   */
  private function recordsOf(NodeInterface $document): array {
    if ((int) $document->id() <= 0) {
      return [];
    }

    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::BUNDLE)
      ->condition(self::DOCUMENT_FIELD, (int) $document->id())
      ->sort('nid')
      ->execute();

    return array_values($this->entityTypeManager->getStorage('node')->loadMultiple($ids));
  }

  /**
   * Human code of a document, for example FV-00002.
   *
   * @param \Drupal\node\NodeInterface $document
   *   The closed document.
   *
   * @return string
   *   The code, or the title of the document when it has no prefix or number.
   */
  private function code(NodeInterface $document): string {
    $number = (int) ($document->get('field_document_number')->value ?? 0);
    if ($number <= 0) {
      return $document->label();
    }

    $prefix = $document->get('field_document_prefix')->entity;
    $code = $prefix instanceof TermInterface ? explode(' - ', $prefix->label())[0] : '';
    if ($code === '') {
      return $document->label();
    }

    return $code . '-' . str_pad((string) $number, 5, '0', STR_PAD_LEFT);
  }

}
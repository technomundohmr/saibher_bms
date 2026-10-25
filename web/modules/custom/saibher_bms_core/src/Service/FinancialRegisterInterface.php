<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Service;

use Drupal\node\NodeInterface;

/**
 * Writes the financial records of a transaction.
 */
interface FinancialRegisterInterface {

  /**
   * Series that adds money to the business.
   */
  public const TYPE_INCOME = 'Ingresos';

  /**
   * Series that isolates the taxes collected from the customer.
   */
  public const TYPE_TAXES = 'Impuestos';

  /**
   * Series with the sale without the taxes collected.
   */
  public const TYPE_NET_INCOME = 'Ingreso sin impuestos';

  /**
   * Series that states what the sale left after paying the cost of the goods.
   */
  public const TYPE_PROFIT = 'Ganancias';

  /**
   * Records the sale of a document in the financial register.
   *
   * A closed sale leaves four records: the money the customer pays with the
   * taxes included, the taxes on their own, the sale without those taxes and
   * the profit, which is what the sale leaves once the cost of the merchandise
   * is subtracted.
   *
   * The records are written once: calling it again for the same document returns
   * the ones it already wrote instead of duplicating them.
   *
   * @param \Drupal\node\NodeInterface $document
   *   The document being closed.
   *
   * @return array<int, \Drupal\node\NodeInterface>
   *   The records of the document, in the order they were written.
   */
  public function recordSale(NodeInterface $document): array;

  /**
   * Money a sale adds to the business, taxes included.
   *
   * @param \Drupal\node\NodeInterface $document
   *   The closed document.
   *
   * @return float
   *   The total the customer paid.
   */
  public function income(NodeInterface $document): float;

  /**
   * Taxes collected with a sale.
   *
   * @param \Drupal\node\NodeInterface $document
   *   The closed document.
   *
   * @return float
   *   The taxes of the document.
   */
  public function taxes(NodeInterface $document): float;

  /**
   * What a sale leaves once the cost of the merchandise is subtracted.
   *
   * The cost comes from the product each line sold, so it answers what that
   * sale cost, not what the catalogue costs today.
   *
   * @param \Drupal\node\NodeInterface $document
   *   The closed document.
   *
   * @return float
   *   The profit of the sale. It is negative when the sale was made below cost.
   */
  public function profit(NodeInterface $document): float;

}
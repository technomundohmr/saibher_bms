<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Service;

use Drupal\node\NodeInterface;

/**
 * Reads the price and the tax rate of a product.
 *
 * The billing form, the catalogue and the saved documents must agree on what a
 * product costs and which tax applies, so the rule lives here instead of being
 * repeated in every place that needs it.
 */
interface ProductPricingInterface {

  /**
   * Selling price of the product.
   *
   * @param \Drupal\node\NodeInterface $product
   *   The product node.
   *
   * @return float
   *   The unit price, or 0.0 when the product has none.
   */
  public function price(NodeInterface $product): float;

  /**
   * Tax percentage that applies to the product.
   *
   * @param \Drupal\node\NodeInterface $product
   *   The product node.
   *
   * @return int
   *   The percentage, or 0 when the product is exempt.
   */
  public function taxPct(NodeInterface $product): int;

}

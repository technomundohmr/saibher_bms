<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Service;

use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Price and tax reader for products.
 */
class ProductPricing implements ProductPricingInterface {

  /**
   * Tax percentage used when the product declares no tax term.
   *
   * The retail catalogue is taxed, so assuming the general rate is safer than
   * quietly saving an untaxed line.
   */
  private const DEFAULT_TAX_PCT = 19;

  /**
   * String that identifies the taxed term of the tax vocabulary.
   */
  private const TAXED_MARKER = '19';

  /**
   * {@inheritdoc}
   */
  public function price(NodeInterface $product): float {
    return (float) ($product->get('field_pvp')->value ?? 0);
  }

  /**
   * {@inheritdoc}
   */
  public function taxPct(NodeInterface $product): int {
    if ($product->hasField('field_tax') && !$product->get('field_tax')->isEmpty()) {
      $term = $product->get('field_tax')->first()?->entity;
      if ($term instanceof TermInterface) {
        return str_contains($term->label(), self::TAXED_MARKER) ? (int) self::TAXED_MARKER : 0;
      }
    }
    return self::DEFAULT_TAX_PCT;
  }

}

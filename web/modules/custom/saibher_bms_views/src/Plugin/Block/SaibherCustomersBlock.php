<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_views\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[Block(id: 'saibher_customers_block', admin_label: new TranslatableMarkup('Saibher: Clientes'), category: new TranslatableMarkup('Saibher'))]
final class SaibherCustomersBlock extends SaibherViewsBlockBase {
  protected const VIEW_ID = 'saibher_customers';
}

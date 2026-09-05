<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_views\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[Block(id: 'saibher_inventory_block', admin_label: new TranslatableMarkup('Saibher: Inventario'), category: new TranslatableMarkup('Saibher'))]
final class SaibherInventoryBlock extends SaibherViewsBlockBase {
  protected const VIEW_ID = 'saibher_inventory';
}

<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_views\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[Block(id: 'saibher_suppliers_block', admin_label: new TranslatableMarkup('Saibher: Proveedores'), category: new TranslatableMarkup('Saibher'))]
final class SaibherSuppliersBlock extends SaibherViewsBlockBase {
  protected const VIEW_ID = 'saibher_suppliers';
}

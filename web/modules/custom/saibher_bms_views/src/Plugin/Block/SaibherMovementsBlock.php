<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_views\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[Block(id: 'saibher_movements_block', admin_label: new TranslatableMarkup('Saibher: Movimientos'), category: new TranslatableMarkup('Saibher'))]
final class SaibherMovementsBlock extends SaibherViewsBlockBase {
  protected const VIEW_ID = 'saibher_movements';
}

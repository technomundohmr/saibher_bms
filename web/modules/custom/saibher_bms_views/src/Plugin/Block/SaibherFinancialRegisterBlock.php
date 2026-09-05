<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_views\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[Block(id: 'saibher_financial_register_block', admin_label: new TranslatableMarkup('Saibher: Registros financieros'), category: new TranslatableMarkup('Saibher'))]
final class SaibherFinancialRegisterBlock extends SaibherViewsBlockBase {
  protected const VIEW_ID = 'saibher_financial_register';
}

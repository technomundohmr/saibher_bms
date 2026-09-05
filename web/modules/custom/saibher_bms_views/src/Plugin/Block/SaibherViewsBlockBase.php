<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_views\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\views\Views;

/**
 * Base block that renders one of the Saibher table Views.
 */
abstract class SaibherViewsBlockBase extends BlockBase {

  /**
   * The View ID rendered by this block.
   */
  protected const VIEW_ID = '';

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $view = Views::getView(static::VIEW_ID);
    if ($view === NULL) {
      throw new \RuntimeException(sprintf('The Saibher View "%s" could not be loaded.', static::VIEW_ID));
    }

    $view->setDisplay('block');
    return $view->render();
  }

}

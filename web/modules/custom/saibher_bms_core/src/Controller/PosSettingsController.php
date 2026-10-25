<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\saibher_bms_core\Form\PosSettingsForm;

/**
 * Página del panel que envuelve la configuración general del punto de venta.
 */
final class PosSettingsController extends ControllerBase {

  /**
   * Página del panel con el formulario de configuración general.
   */
  public function page(): array {
    $form = $this->formBuilder()->getForm(PosSettingsForm::class);
    return [
      '#theme' => 'saibher_bms_core_config_page',
      '#title' => $this->t('Configuración'),
      '#subtitle' => $this->t('Los datos y preferencias de tu negocio.'),
      '#icon' => 'settings',
      '#form' => $form,
      '#attached' => [
        'library' => ['saibher_bms_core/admin-pages'],
      ],
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => ['config:saibher_bms_core.settings'],
      ],
    ];
  }

}

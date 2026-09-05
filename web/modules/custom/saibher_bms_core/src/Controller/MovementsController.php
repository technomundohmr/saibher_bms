<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Controller;

use Drupal\saibher_bms_core\Form\MovementModalForm;

final class MovementsController extends OperationalPageControllerBase {

  public function page(): array {
    return $this->buildOperationalPage(
      'saibher_movements',
      'Movimientos de inventario',
      'Registro detallado del flujo de mercancía.',
      'swap_horiz',
      'Registrar movimiento',
      MovementModalForm::class,
    );
  }

}


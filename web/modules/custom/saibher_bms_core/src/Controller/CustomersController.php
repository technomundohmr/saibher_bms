<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Controller;

use Drupal\saibher_bms_core\Form\CustomerModalForm;

final class CustomersController extends OperationalPageControllerBase {

  public function page(): array {
    return $this->buildOperationalPage(
      'saibher_customers',
      'Gestión de clientes',
      'Administra la base de datos de clientes y su estado de cuenta.',
      'groups',
      'Añadir cliente',
      CustomerModalForm::class,
    );
  }

}


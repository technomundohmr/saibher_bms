<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Controller;

final class SuppliersController extends OperationalPageControllerBase {

  public function page(): array {
    return $this->buildOperationalPage(
      'saibher_suppliers',
      'Proveedores',
      'Gestiona el directorio de distribuidores y fabricantes.',
      'local_shipping',
      'Añadir proveedor',
      'supplier',
    );
  }

}


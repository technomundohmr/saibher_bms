<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Controller;

final class InventoryController extends OperationalPageControllerBase {

  public function page(): array {
    return $this->buildOperationalPage(
      'saibher_inventory',
      'Inventario',
      'Gestiona los productos y existencias de la tienda.',
      'inventory_2',
      'Añadir producto',
      'product',
    );
  }

}


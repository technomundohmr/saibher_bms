<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Form;

final class ProductModalForm extends EntityModalFormBase {

  protected const BUNDLE = 'product';

  protected const FIELDS = [
    'title' => [
      'label' => 'Nombre del producto',
      'required' => true,
      'placeholder' => 'Ej. Agua mineral 500ml',
    ],
    'field_sku' => [
      'label' => 'SKU',
      'required' => true,
      'placeholder' => 'BEB-042',
    ],
    'field_stock' => [
      'label' => 'Stock inicial',
      'type' => 'number',
      'placeholder' => '0',
    ],
    'field_min_stock' => [
      'label' => 'Stock mínimo',
      'type' => 'number',
      'placeholder' => '0',
    ],
    'field_pvp' => [
      'label' => 'Precio de venta',
      'type' => 'number',
      'placeholder' => '0.00',
    ],
  ];

  protected function entityLabel(): string {
    return (string) $this->t('Producto');
  }

  protected function submitLabel(): string {
    return (string) $this->t('Guardar producto');
  }

}


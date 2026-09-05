<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Form;

final class MovementModalForm extends EntityModalFormBase {

  protected const BUNDLE = 'movement';

  protected const FIELDS = [
    'title' => [
      'label' => 'Descripción del movimiento',
      'required' => true,
      'placeholder' => 'Ej. Entrada de mercancía',
    ],
    'field_product' => [
      'label' => 'Producto',
      'required' => true,
      'placeholder' => 'Nombre del producto',
    ],
    'field_product_code' => [
      'label' => 'SKU',
      'placeholder' => 'SKU-001',
    ],
    'field_movement_type' => [
      'label' => 'Tipo de movimiento',
      'type' => 'select',
      'required' => true,
      'options' => [
        '' => 'Selecciona un tipo',
        'Entrada' => 'Entrada',
        'Salida' => 'Salida',
        'Ajuste' => 'Ajuste',
        'Merma/Robo' => 'Merma/Robo',
      ],
    ],
    'field_quantity' => [
      'label' => 'Cantidad',
      'type' => 'number',
      'required' => true,
      'placeholder' => '0',
    ],
    'field_reference' => [
      'label' => 'Referencia',
      'placeholder' => 'OC-2026-0001',
    ],
  ];

  protected function entityLabel(): string {
    return (string) $this->t('Movimiento');
  }

  protected function submitLabel(): string {
    return (string) $this->t('Guardar movimiento');
  }

}


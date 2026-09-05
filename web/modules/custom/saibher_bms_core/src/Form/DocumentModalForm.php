<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Form;

final class DocumentModalForm extends EntityModalFormBase {

  protected const BUNDLE = 'document';

  protected const FIELDS = [
    'title' => [
      'label' => 'Nombre del documento',
      'required' => true,
      'placeholder' => 'Ej. Venta mostrador',
    ],
    'field_document_number' => [
      'label' => 'Número de documento',
      'type' => 'number',
      'required' => true,
      'placeholder' => '1001',
    ],
    'field_document_type_document' => [
      'label' => 'Tipo de documento',
      'type' => 'select',
      'required' => true,
      'options' => [
        '' => 'Selecciona un tipo',
        'Factura de venta' => 'Factura de venta',
        'Factura de compra' => 'Factura de compra',
        'Ticket' => 'Ticket',
        'Cotización' => 'Cotización',
        'Orden de compra' => 'Orden de compra',
      ],
    ],
    'field_subtotal' => [
      'label' => 'Subtotal',
      'type' => 'number',
      'placeholder' => '0.00',
    ],
    'field_total_tax' => [
      'label' => 'Impuestos',
      'type' => 'number',
      'placeholder' => '0.00',
    ],
    'field_total' => [
      'label' => 'Total',
      'type' => 'number',
      'required' => true,
      'placeholder' => '0.00',
    ],
  ];

  protected function entityLabel(): string {
    return (string) $this->t('Documento');
  }

  protected function submitLabel(): string {
    return (string) $this->t('Generar factura');
  }

}


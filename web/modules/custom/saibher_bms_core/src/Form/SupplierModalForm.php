<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Form;

final class SupplierModalForm extends EntityModalFormBase {

  protected const BUNDLE = 'supplier';

  protected const FIELDS = [
    'title' => [
      'label' => 'Razón social o nombre',
      'required' => true,
      'placeholder' => 'Ej. Distribuidora Andina S.A.',
    ],
    'field_document_nunber' => [
      'label' => 'NIT / identificación fiscal',
      'required' => true,
      'placeholder' => '900.123.456-7',
    ],
    'field_person_name' => [
      'label' => 'Persona de contacto',
      'placeholder' => 'Nombre del representante',
    ],
    'field_phone_supplier' => [
      'label' => 'Teléfono principal',
      'placeholder' => '+57 300 000 0000',
    ],
    'field_email_supplier' => [
      'label' => 'Correo electrónico',
      'type' => 'email',
      'placeholder' => 'contacto@empresa.com',
    ],
    'field_main_address' => [
      'label' => 'Dirección comercial',
      'type' => 'textarea',
      'placeholder' => 'Dirección completa, bodega o local',
    ],
  ];

  protected function entityLabel(): string {
    return (string) $this->t('Proveedor');
  }

  protected function submitLabel(): string {
    return (string) $this->t('Guardar proveedor');
  }

}


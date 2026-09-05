<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Form;

final class CustomerModalForm extends EntityModalFormBase {

  protected const BUNDLE = 'customer';

  protected const FIELDS = [
    'title' => [
      'label' => 'Nombre o razón social',
      'required' => true,
      'placeholder' => 'Ej. Martín Pérez',
    ],
    'field_person_name' => [
      'label' => 'Persona de contacto',
      'placeholder' => 'Nombre completo',
    ],
    'field_document_nunber' => [
      'label' => 'Documento',
      'placeholder' => 'CC o NIT',
    ],
    'field_phone_customer' => [
      'label' => 'Teléfono',
      'placeholder' => '+57 300 000 0000',
    ],
    'field_email_customer' => [
      'label' => 'Correo electrónico',
      'type' => 'email',
      'placeholder' => 'cliente@empresa.com',
    ],
    'field_main_address' => [
      'label' => 'Dirección de facturación',
      'placeholder' => 'Dirección completa',
    ],
  ];

  protected function entityLabel(): string {
    return (string) $this->t('Cliente');
  }

  protected function submitLabel(): string {
    return (string) $this->t('Guardar cliente');
  }

}


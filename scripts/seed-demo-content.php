<?php

/**
 * @file
 * Crea datos de demostración idempotentes para Saibher BMS.
 *
 * Ejecutar con: ddev exec drush php:script scripts/seed-demo-content.php
 */

use Drupal\Core\Entity\EntityStorageInterface;

$term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$node_storage = \Drupal::entityTypeManager()->getStorage('node');
$paragraph_storage = \Drupal::entityTypeManager()->getStorage('paragraph');

/** @var \Closure $term */
$term = static function (string $vid, string $name, array $values = []) use ($term_storage) {
  $ids = $term_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('vid', $vid)
    ->condition('name', $name)
    ->range(0, 1)
    ->execute();

  if ($ids) {
    return $term_storage->load(reset($ids));
  }

  $entity = $term_storage->create([
    'vid' => $vid,
    'name' => $name,
    'status' => 1,
  ] + $values);
  $entity->save();
  return $entity;
};

/** @var \Closure $node */
$node = static function (string $type, string $title, array $values = []) use ($node_storage) {
  $ids = $node_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', $type)
    ->condition('title', $title)
    ->range(0, 1)
    ->execute();

  if ($ids) {
    return $node_storage->load(reset($ids));
  }

  $entity = $node_storage->create([
    'type' => $type,
    'title' => $title,
    'status' => 1,
  ] + $values);
  $entity->save();
  return $entity;
};

// Taxonomías.
$category = $term('category', 'Bebidas de prueba');
$term('category', 'Aseo de prueba');
$prefix = $term('document_prefix', 'FV - Factura de venta');
$method = $term('payment_method', 'Transferencia bancaria');
$term('payment_method', 'Efectivo');
$person_document = $term('person_document_type', 'NIT');
$term('person_document_type', 'Cédula de ciudadanía');
$tax = $term('tax', 'IVA 19%', ['field_percentage' => 19]);
$term('tax', 'Exento', ['field_percentage' => 0]);
$term('tags', 'Demostración Saibher');

// Entidades independientes y referencias base.
$register = $node('register', 'DEMO - Ingreso de caja', [
  'field_register_type' => 'Ganancias',
  'field_reference' => 'ING-DEMO-001',
  'field_value' => 150000,
]);

$location = $node('locations', 'DEMO - Sede principal', [
  'field_name' => 'Sede principal',
  'field_address' => 'Calle 100 # 15-20, Bogotá',
  'field_person_name' => 'Laura Gómez',
  'field_phone_locations' => '+57 300 555 0101',
  'field_email_locations' => 'sede.demo@saibher.test',
]);

$product = $node('product', 'DEMO - Agua mineral 500 ml', [
  'field_code' => 'BEB-DEMO-001',
  'field_sku' => 'BEB-500-DEMO',
  'field_pvp' => 3500,
  'field_cost' => 1800,
  'field_stock' => 48,
  'field_min_stock' => 12,
  'field_active' => TRUE,
  'field_public_webpage' => TRUE,
  'field_category' => ['target_id' => $category->id()],
  'field_tax' => ['target_id' => $tax->id()],
  'field_description' => [
    'value' => 'Producto de demostración para el inventario Saibher.',
    'format' => 'plain_text',
  ],
]);

$customer = $node('customer', 'DEMO - Comercializadora Andina', [
  'field_document_nunber' => '901234567-8',
  'field_document_type_customer' => ['target_id' => $person_document->id()],
  'field_email_customer' => 'cliente.demo@saibher.test',
  'field_locations' => ['target_id' => $location->id()],
  'field_main_address' => 'Carrera 7 # 72-41, Bogotá',
  'field_payment_customer' => ['target_id' => $register->id()],
  'field_person_name' => 'María Fernanda Ruiz',
  'field_phone_customer' => '+57 310 555 0202',
]);

if ($location->get('field_customer_locations')->isEmpty()) {
  $location->set('field_customer_locations', ['target_id' => $customer->id()]);
  $location->save();
}

$node('supplier', 'DEMO - Distribuidora Central', [
  'field_document_nunber' => '900765432-1',
  'field_document_type_supplier' => ['target_id' => $person_document->id()],
  'field_email_supplier' => 'proveedor.demo@saibher.test',
  'field_locations' => ['target_id' => $location->id()],
  'field_main_address' => 'Avenida 68 # 20-10, Bogotá',
  'field_person_name' => 'Carlos Torres',
  'field_phone_supplier' => '+57 315 555 0303',
]);

// Documento y sus párrafos de detalle y pago.
$document_title = 'DEMO - Factura FV-1001';
$document_ids = $node_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'document')
  ->condition('title', $document_title)
  ->range(0, 1)
  ->execute();

if (!$document_ids) {
  $item = $paragraph_storage->create([
    'type' => 'document_item',
    'field_product' => 'BEB-500-DEMO',
    'field_quantity' => 2,
    'field_unit_value' => 3500,
    'field_tax_percentage' => 19,
    'field_tax_unit_value' => 665,
    'field_total_tax' => 1330,
    'field_total' => 8330,
  ]);
  $item->save();

  $payment = $paragraph_storage->create([
    'type' => 'payment',
    'field_method' => ['target_id' => $method->id()],
    'field_quantity' => 8330,
    'field_reference' => 'TRX-DEMO-001',
  ]);
  $payment->save();

  $node('document', $document_title, [
    'field_customer_document' => ['target_id' => $customer->id()],
    'field_document_item' => [[
      'target_id' => $item->id(),
      'target_revision_id' => $item->getRevisionId(),
    ]],
    'field_document_number' => 1001,
    'field_document_prefix' => ['target_id' => $prefix->id()],
    'field_document_type_document' => 'Factura de venta',
    'field_payment_document' => [[
      'target_id' => $payment->id(),
      'target_revision_id' => $payment->getRevisionId(),
    ]],
    'field_subtotal' => 7000,
    'field_total_tax' => 1330,
    'field_total' => 8330,
  ]);
}

$node('debtors', 'DEMO - Cartera Comercializadora Andina', [
  'field_customer_debtors' => ['target_id' => $customer->id()],
  'field_reference' => 'FV-1001',
  'field_value' => 8330,
]);

$node('movement', 'DEMO - Entrada de agua mineral', [
  'field_initial_quantity' => 30,
  'field_final_quantity' => 48,
  'field_movement_type' => 'Entrada',
  'field_product' => 'DEMO - Agua mineral 500 ml',
  'field_product_code' => 'BEB-500-DEMO',
  'field_quantity' => 18,
  'field_reference' => 'OC-DEMO-001',
]);

$node('tax_register', 'DEMO - IVA generado', [
  'field_tax_type' => ['target_id' => $tax->id()],
  'field_value' => 1330,
]);

print "Datos de prueba creados o verificados: 6 vocabularios, 1 etiqueta, 9 tipos de contenido y 2 párrafos.\n";

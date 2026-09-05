<?php

/**
 * @file
 * Crea 20 registros adicionales por cada tipo de contenido Saibher BMS.
 *
 * Ejecutar con: ddev exec drush php:script scripts/seed-bulk-demo-content.php
 */

$term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$node_storage = \Drupal::entityTypeManager()->getStorage('node');
$paragraph_storage = \Drupal::entityTypeManager()->getStorage('paragraph');

$find_term = static function (string $vid, string $name) use ($term_storage) {
  $ids = $term_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('vid', $vid)
    ->condition('name', $name)
    ->range(0, 1)
    ->execute();
  return $ids ? $term_storage->load(reset($ids)) : NULL;
};

$create_node = static function (string $type, string $title, array $values = []) use ($node_storage) {
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

$category = $find_term('category', 'Bebidas de prueba');
$prefix = $find_term('document_prefix', 'FV - Factura de venta');
$method = $find_term('payment_method', 'Transferencia bancaria');
$person_document = $find_term('person_document_type', 'NIT');
$tax = $find_term('tax', 'IVA 19%');

if (!$category || !$prefix || !$method || !$person_document || !$tax) {
  throw new \RuntimeException('Ejecuta primero scripts/seed-demo-content.php para crear las taxonomías base.');
}

for ($i = 1; $i <= 20; $i++) {
  $number = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
  $product_code = "BEB-LOTE-$number";
  $document_title = "DEMO LOTE $number - Factura FV-" . (1100 + $i);

  $register = $create_node('register', "DEMO LOTE $number - Registro de caja", [
    'field_register_type' => $i % 2 ? 'Ganancias' : 'Gastos',
    'field_reference' => "REG-LOTE-$number",
    'field_value' => 100000 + ($i * 5000),
  ]);

  $location = $create_node('locations', "DEMO LOTE $number - Sede", [
    'field_name' => "Sede de prueba $number",
    'field_address' => "Calle " . (80 + $i) . " # " . (10 + $i) . "-" . (20 + $i) . ', Bogotá',
    'field_person_name' => "Contacto Sede $number",
    'field_phone_locations' => '+57 300 600 ' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
    'field_email_locations' => "sede.$number@saibher.test",
  ]);

  $product = $create_node('product', "DEMO LOTE $number - Bebida", [
    'field_code' => "COD-LOTE-$number",
    'field_sku' => $product_code,
    'field_pvp' => 3000 + ($i * 100),
    'field_cost' => 1500 + ($i * 50),
    'field_stock' => 20 + $i,
    'field_min_stock' => 8,
    'field_active' => TRUE,
    'field_public_webpage' => TRUE,
    'field_category' => ['target_id' => $category->id()],
    'field_tax' => ['target_id' => $tax->id()],
  ]);

  $customer = $create_node('customer', "DEMO LOTE $number - Cliente", [
    'field_document_nunber' => '901' . str_pad((string) $i, 6, '0', STR_PAD_LEFT),
    'field_document_type_customer' => ['target_id' => $person_document->id()],
    'field_email_customer' => "cliente.$number@saibher.test",
    'field_locations' => ['target_id' => $location->id()],
    'field_main_address' => "Carrera " . (10 + $i) . " # 50-" . (10 + $i) . ', Bogotá',
    'field_payment_customer' => ['target_id' => $register->id()],
    'field_person_name' => "Contacto Cliente $number",
    'field_phone_customer' => '+57 310 700 ' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
  ]);

  if ($location->get('field_customer_locations')->isEmpty()) {
    $location->set('field_customer_locations', ['target_id' => $customer->id()]);
    $location->save();
  }

  $create_node('supplier', "DEMO LOTE $number - Proveedor", [
    'field_document_nunber' => '900' . str_pad((string) $i, 6, '0', STR_PAD_LEFT),
    'field_document_type_supplier' => ['target_id' => $person_document->id()],
    'field_email_supplier' => "proveedor.$number@saibher.test",
    'field_locations' => ['target_id' => $location->id()],
    'field_main_address' => "Avenida " . (30 + $i) . " # 40-" . (10 + $i) . ', Bogotá',
    'field_person_name' => "Contacto Proveedor $number",
    'field_phone_supplier' => '+57 315 800 ' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
  ]);

  $document_ids = $node_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'document')
    ->condition('title', $document_title)
    ->range(0, 1)
    ->execute();

  if (!$document_ids) {
    $unit_value = 3000 + ($i * 100);
    $total_tax = $unit_value * 0.19;
    $item = $paragraph_storage->create([
      'type' => 'document_item',
      'field_product' => $product_code,
      'field_quantity' => 1,
      'field_unit_value' => $unit_value,
      'field_tax_percentage' => 19,
      'field_tax_unit_value' => $total_tax,
      'field_total_tax' => $total_tax,
      'field_total' => $unit_value + $total_tax,
    ]);
    $item->save();

    $payment = $paragraph_storage->create([
      'type' => 'payment',
      'field_method' => ['target_id' => $method->id()],
      'field_quantity' => $unit_value + $total_tax,
      'field_reference' => "TRX-LOTE-$number",
    ]);
    $payment->save();

    $create_node('document', $document_title, [
      'field_customer_document' => ['target_id' => $customer->id()],
      'field_document_item' => [[
        'target_id' => $item->id(),
        'target_revision_id' => $item->getRevisionId(),
      ]],
      'field_document_number' => 1100 + $i,
      'field_document_prefix' => ['target_id' => $prefix->id()],
      'field_document_type_document' => 'Factura de venta',
      'field_payment_document' => [[
        'target_id' => $payment->id(),
        'target_revision_id' => $payment->getRevisionId(),
      ]],
      'field_subtotal' => $unit_value,
      'field_total_tax' => $total_tax,
      'field_total' => $unit_value + $total_tax,
    ]);
  }

  $create_node('debtors', "DEMO LOTE $number - Cartera", [
    'field_customer_debtors' => ['target_id' => $customer->id()],
    'field_reference' => "FV-" . (1100 + $i),
    'field_value' => 3000 + ($i * 100),
  ]);

  $create_node('movement', "DEMO LOTE $number - Movimiento", [
    'field_initial_quantity' => 20,
    'field_final_quantity' => 20 + $i,
    'field_movement_type' => $i % 2 ? 'Entrada' : 'Salida',
    'field_product' => $product->label(),
    'field_product_code' => $product_code,
    'field_quantity' => $i,
    'field_reference' => "MOV-LOTE-$number",
  ]);

  $create_node('tax_register', "DEMO LOTE $number - Impuesto", [
    'field_tax_type' => ['target_id' => $tax->id()],
    'field_value' => 570 + ($i * 19),
  ]);
}

print "Se crearon o verificaron 20 registros adicionales por cada uno de los 9 tipos de contenido.\n";

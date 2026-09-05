<?php

/**
 * @file
 * Crea vistas administrativas tabulares para los módulos principales.
 *
 * Ejecutar con: ddev exec drush php:script scripts/create-admin-table-views.php
 */

$view_storage = \Drupal::entityTypeManager()->getStorage('view');
$base_view = $view_storage->load('content');

if (!$base_view) {
  throw new \RuntimeException('No se encontró la vista base content.');
}

$field = static function (string $id, string $table, string $field_name, string $label, string $plugin = 'field', array $extra = []): array {
  return [
    'id' => $id,
    'table' => $table,
    'field' => $field_name,
    'relationship' => 'none',
    'group_type' => 'group',
    'admin_label' => '',
    'entity_type' => 'node',
    'entity_field' => $field_name,
    'plugin_id' => $plugin,
    'label' => $label,
    'exclude' => FALSE,
    'alter' => ['alter_text' => FALSE],
    'element_type' => '',
    'element_class' => '',
    'element_label_type' => '',
    'element_label_class' => '',
    'element_label_colon' => TRUE,
    'element_wrapper_type' => '',
    'element_wrapper_class' => '',
    'element_default_classes' => TRUE,
    'empty' => '',
    'hide_empty' => FALSE,
    'empty_zero' => FALSE,
    'hide_alter_empty' => TRUE,
  ] + $extra;
};

$operation_field = [
  'id' => 'operations',
  'table' => 'node',
  'field' => 'operations',
  'relationship' => 'none',
  'group_type' => 'group',
  'admin_label' => '',
  'entity_type' => 'node',
  'plugin_id' => 'entity_operations',
  'label' => 'Acciones',
  'exclude' => FALSE,
  'alter' => ['alter_text' => FALSE],
  'element_type' => '',
  'element_class' => '',
  'element_label_type' => '',
  'element_label_class' => '',
  'element_label_colon' => TRUE,
  'element_wrapper_type' => '',
  'element_wrapper_class' => '',
  'element_default_classes' => TRUE,
  'empty' => '',
  'hide_empty' => FALSE,
  'empty_zero' => FALSE,
  'hide_alter_empty' => TRUE,
];

$entity_ref = static function (string $id, string $field_name, string $label) use ($field): array {
  return $field($id, "node__$field_name", $field_name, $label, 'field', [
    'type' => 'entity_reference_label',
    'settings' => ['link' => FALSE],
  ]);
};

$date_field = $field('created', 'node_field_data', 'created', 'Creado', 'date', [
  'type' => 'timestamp',
  'settings' => [
    'date_format' => 'short',
    'custom_date_format' => '',
    'timezone' => '',
    'tooltip' => ['date_format' => 'long', 'custom_date_format' => ''],
    'time_diff' => ['enabled' => FALSE, 'future_format' => '@interval hence', 'past_format' => '@interval ago', 'granularity' => 2, 'refresh' => 60],
  ],
]);

$view_definitions = [
  'saibher_inventory' => [
    'label' => 'Inventario', 'bundle' => 'product', 'title' => 'Inventario', 'path' => 'admin/saibher/inventario',
    'fields' => [
      $field('title', 'node_field_data', 'title', 'Producto', 'field', ['type' => 'string', 'settings' => ['link_to_entity' => TRUE]]),
      $field('field_sku', 'node__field_sku', 'field_sku', 'SKU'),
      $field('field_code', 'node__field_code', 'field_code', 'Código'),
      $entity_ref('field_category', 'field_category', 'Categoría'),
      $field('field_stock', 'node__field_stock', 'field_stock', 'Existencias'),
      $field('field_min_stock', 'node__field_min_stock', 'field_min_stock', 'Mínimo'),
      $field('field_pvp', 'node__field_pvp', 'field_pvp', 'Precio de venta'),
      $field('field_active', 'node__field_active', 'field_active', 'Estado', 'field', ['type' => 'boolean', 'settings' => ['format' => 'custom', 'format_custom_true' => 'Activo', 'format_custom_false' => 'Inactivo']]),
      $operation_field,
    ],
  ],
  'saibher_customers' => [
    'label' => 'Clientes', 'bundle' => 'customer', 'title' => 'Clientes', 'path' => 'admin/saibher/clientes',
    'fields' => [
      $field('title', 'node_field_data', 'title', 'Cliente', 'field', ['type' => 'string', 'settings' => ['link_to_entity' => TRUE]]),
      $field('field_document_nunber', 'node__field_document_nunber', 'field_document_nunber', 'Documento'),
      $field('field_person_name', 'node__field_person_name', 'field_person_name', 'Contacto'),
      $field('field_phone_customer', 'node__field_phone_customer', 'field_phone_customer', 'Teléfono'),
      $field('field_email_customer', 'node__field_email_customer', 'field_email_customer', 'Correo'),
      $field('field_main_address', 'node__field_main_address', 'field_main_address', 'Dirección'),
      $operation_field,
    ],
  ],
  'saibher_suppliers' => [
    'label' => 'Proveedores', 'bundle' => 'supplier', 'title' => 'Proveedores', 'path' => 'admin/saibher/proveedores',
    'fields' => [
      $field('title', 'node_field_data', 'title', 'Proveedor', 'field', ['type' => 'string', 'settings' => ['link_to_entity' => TRUE]]),
      $field('field_document_nunber', 'node__field_document_nunber', 'field_document_nunber', 'NIT'),
      $field('field_person_name', 'node__field_person_name', 'field_person_name', 'Contacto'),
      $field('field_phone_supplier', 'node__field_phone_supplier', 'field_phone_supplier', 'Teléfono'),
      $field('field_email_supplier', 'node__field_email_supplier', 'field_email_supplier', 'Correo'),
      $field('field_main_address', 'node__field_main_address', 'field_main_address', 'Dirección'),
      $operation_field,
    ],
  ],
  'saibher_registers' => [
    'label' => 'Registros financieros', 'bundle' => 'register', 'title' => 'Registros financieros', 'path' => 'admin/saibher/registros',
    'fields' => [
      $field('title', 'node_field_data', 'title', 'Registro', 'field', ['type' => 'string', 'settings' => ['link_to_entity' => TRUE]]),
      $field('field_register_type', 'node__field_register_type', 'field_register_type', 'Tipo'),
      $field('field_reference', 'node__field_reference', 'field_reference', 'Referencia'),
      $field('field_value', 'node__field_value', 'field_value', 'Valor'),
      $date_field,
      $operation_field,
    ],
  ],
  'saibher_movements' => [
    'label' => 'Movimientos de inventario', 'bundle' => 'movement', 'title' => 'Movimientos de inventario', 'path' => 'admin/saibher/movimientos',
    'fields' => [
      $field('title', 'node_field_data', 'title', 'Movimiento', 'field', ['type' => 'string', 'settings' => ['link_to_entity' => TRUE]]),
      $field('field_product', 'node__field_product', 'field_product', 'Producto'),
      $field('field_product_code', 'node__field_product_code', 'field_product_code', 'SKU'),
      $field('field_movement_type', 'node__field_movement_type', 'field_movement_type', 'Tipo'),
      $field('field_quantity', 'node__field_quantity', 'field_quantity', 'Cantidad'),
      $field('field_initial_quantity', 'node__field_initial_quantity', 'field_initial_quantity', 'Inicial'),
      $field('field_final_quantity', 'node__field_final_quantity', 'field_final_quantity', 'Final'),
      $field('field_reference', 'node__field_reference', 'field_reference', 'Referencia'),
      $date_field,
      $operation_field,
    ],
  ],
];

foreach ($view_definitions as $id => $definition) {
  $view = $view_storage->load($id) ?: $base_view->createDuplicate();
  $view->set('id', $id);
  $view->set('label', $definition['label']);
  $view->set('description', 'Vista tabular administrativa de Saibher BMS.');
  $view->set('tag', 'saibher');

  $executable = $view->getExecutable();
  foreach (['default', 'page_1'] as $display_id) {
    $executable->setDisplay($display_id);
    $display = $executable->getDisplay();
    $display->setOption('title', $definition['title']);
    $display->setOption('fields', $definition['fields']);
    $display->setOption('filters', [
      'type' => [
        'id' => 'type', 'table' => 'node_field_data', 'field' => 'type', 'relationship' => 'none', 'group_type' => 'group',
        'admin_label' => '', 'entity_type' => 'node', 'entity_field' => 'type', 'plugin_id' => 'bundle', 'operator' => 'in',
        'value' => [$definition['bundle'] => $definition['bundle']], 'group' => 1, 'exposed' => FALSE,
      ],
      'status' => [
        'id' => 'status', 'table' => 'node_field_data', 'field' => 'status', 'relationship' => 'none', 'group_type' => 'group',
        'admin_label' => '', 'entity_type' => 'node', 'entity_field' => 'status', 'plugin_id' => 'boolean', 'operator' => '=',
        'value' => '1', 'group' => 1, 'exposed' => FALSE,
      ],
    ]);
    $field_ids = array_keys($definition['fields']);
    $columns = [];
    $info = [];
    foreach ($definition['fields'] as $field_id => $field_definition) {
      $columns[$field_id] = $field_id;
      $info[$field_id] = ['sortable' => $field_id !== 'operations', 'default_sort_order' => $field_id === 'title' ? 'asc' : 'desc', 'align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => $field_id === 'operations' ? '' : 'priority-medium'];
    }
    $display->setOption('style', ['type' => 'table', 'options' => ['grouping' => [], 'row_class' => '', 'default_row_class' => TRUE, 'columns' => $columns, 'default' => 'title', 'info' => $info, 'override' => TRUE, 'sticky' => TRUE, 'summary' => '', 'empty_table' => TRUE, 'caption' => '', 'description' => '', 'class' => 'sb-data-table']]);
    $display->setOption('row', ['type' => 'fields']);
    $display->setOption('pager', ['type' => 'full', 'options' => ['offset' => 0, 'items_per_page' => 25, 'id' => 0, 'total_pages' => NULL, 'expose' => ['items_per_page' => FALSE, 'items_per_page_label' => 'Elementos por página', 'items_per_page_options' => '5, 10, 25, 50', 'items_per_page_options_all' => FALSE, 'offset' => FALSE, 'offset_label' => 'Desplazamiento', 'id' => '']]]);
    if ($display_id === 'page_1') {
      $display->setOption('path', $definition['path']);
      $display->setOption('menu', ['type' => 'none', 'title' => '', 'description' => '', 'weight' => 0, 'menu_name' => 'main', 'context' => '']);
    }
  }
  $view->save();
  print "Vista guardada: $id\n";
}

print "Vistas tabulares creadas o actualizadas.\n";

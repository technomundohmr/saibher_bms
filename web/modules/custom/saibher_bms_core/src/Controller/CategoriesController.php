<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Controller;

/**
 * "Categorías" operational page listing the product categories taxonomy.
 */
final class CategoriesController extends OperationalPageControllerBase {

  public function page(): array {
    return $this->buildOperationalPage(
      'saibher_categories',
      'Categorías',
      'Gestiona las categorías de producto del negocio.',
      'category',
      'Añadir categoría',
      NULL,
      NULL,
      NULL,
      NULL,
      'category',
    );
  }

}
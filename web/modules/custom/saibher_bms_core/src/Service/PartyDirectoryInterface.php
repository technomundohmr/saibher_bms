<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Service;

/**
 * Lectura de las partes (clientes y proveedores) de un formulario de documento.
 *
 * El formulario nunca recibe la lista completa: pide a este servicio las
 * coincidencias de lo que el usuario está escribiendo y, cuando elige una
 * parte, el registro que completa el formulario.
 */
interface PartyDirectoryInterface {

  /**
   * Tipos de contenido que este directorio puede leer.
   *
   * @return string[]
   *   Bundles aceptados por los métodos de búsqueda y detalle.
   */
  public function bundles(): array;

  /**
   * Devuelve las partes que coinciden con lo que el usuario escribe.
   *
   * @param string $bundle
   *   Tipo de contenido de la parte: 'customer' o 'supplier'.
   * @param string $term
   *   Texto escrito por el usuario.
   * @param string $mode
   *   Modo de búsqueda: 'name' compara nombre, contacto y número de
   *   identificación; 'doc' exige el número completo.
   * @param int $limit
   *   Máximo de coincidencias a devolver.
   *
   * @return array<int, array{id: int, label: string, suffix: string}>
   *   Coincidencias listas para listar; vacío si el término es demasiado corto
   *   o no hay coincidencias.
   *
   * @throws \InvalidArgumentException
   *   Si el bundle no es un tipo de contenido de parte.
   */
  public function search(string $bundle, string $term, string $mode = 'name', int $limit = 10): array;

  /**
   * Devuelve el registro de la parte elegida para completar el formulario.
   *
   * @param string $bundle
   *   Tipo de contenido de la parte: 'customer' o 'supplier'.
   * @param int $id
   *   ID de nodo de la parte.
   *
   * @return array<string, mixed>|null
   *   Los datos de la parte, o NULL si no existe o el usuario actual no tiene
   *   acceso de lectura.
   *
   * @throws \InvalidArgumentException
   *   Si el bundle no es un tipo de contenido de parte.
   */
  public function get(string $bundle, int $id): ?array;

}

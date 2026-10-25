<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Service;

/**
 * Consecutivo de los documentos del punto de venta.
 *
 * Resuelve el número que corresponde al siguiente documento de un tipo
 * concreto (venta, compra o ticket) combinando la semilla configurada y los
 * documentos ya emitidos.
 */
interface DocumentNumberingInterface {

  /**
   * Tipos de documento que tienen numeración propia.
   *
   * @return string[]
   *   Claves de tipo aceptadas por el resto de métodos.
   */
  public function kinds(): array;

  /**
   * Número inicial configurado para un tipo de documento.
   *
   * @param string $kind
   *   Clave de tipo: 'venta', 'compra' o 'ticket'.
   *
   * @return int
   *   La semilla configurada, o 0 si no hay ninguna.
   *
   * @throws \InvalidArgumentException
   *   Si el tipo no tiene numeración propia.
   */
  public function initial(string $kind): int;

  /**
   * Mayor número ya emitido para un tipo de documento.
   *
   * @param string $kind
   *   Clave de tipo: 'venta', 'compra' o 'ticket'.
   *
   * @return int
   *   El número más alto emitido, o 0 si todavía no se emitió ninguno.
   *
   * @throws \InvalidArgumentException
   *   Si el tipo no tiene numeración propia.
   */
  public function highest(string $kind): int;

  /**
   * Número que corresponde al siguiente documento de un tipo.
   *
   * @param string $kind
   *   Clave de tipo: 'venta', 'compra' o 'ticket'.
   *
   * @return int
   *   El consecutivo siguiente, siempre mayor que cualquier número emitido.
   *
   * @throws \InvalidArgumentException
   *   Si el tipo no tiene numeración propia.
   */
  public function next(string $kind): int;

}

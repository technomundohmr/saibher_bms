<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Exception;

/**
 * Thrown when a document would take the stock of a product below zero.
 *
 * The exception only travels when the configuration forbids selling without
 * stock; otherwise the shortages are reported back to the form as a warning and
 * the document is saved anyway.
 */
class InsufficientStockException extends \RuntimeException {

  /**
   * Constructs an InsufficientStockException.
   *
   * @param array<int, array{name: string, available: float, requested: float}> $shortages
   *   Products that do not have enough units, with the stock they have and the
   *   amount the document asks for.
   */
  public function __construct(
    private readonly array $shortages,
  ) {
    parent::__construct('No hay inventario suficiente para uno o más productos de la factura.');
  }

  /**
   * Products that did not have enough units.
   *
   * @return array<int, array{name: string, available: float, requested: float}>
   *   The shortages, ready to be sent to the browser.
   */
  public function shortages(): array {
    return $this->shortages;
  }

}
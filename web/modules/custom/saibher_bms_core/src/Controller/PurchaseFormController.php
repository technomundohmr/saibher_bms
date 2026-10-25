<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Controller;

/**
 * Renders the single-page purchase form (visual stage).
 *
 * Records purchases from suppliers: the shared document form is configured
 * for the purchase mode, so the party card searches suppliers and the
 * resulting document is a purchase invoice.
 */
final class PurchaseFormController extends DocumentFormControllerBase {

  protected function partyType(): string {
    return 'supplier';
  }

  protected function pageTitle(): string {
    return 'Comprar';
  }

  protected function pageSubtitle(): string {
    return 'Formulario de compra con cálculo automático de impuestos y totales.';
  }

  protected function icon(): string {
    return 'shopping_cart';
  }

  protected function partyLabel(): string {
    return 'Proveedor';
  }

  protected function documentTitle(): string {
    return 'Compra';
  }

  protected function prefixKeyword(): string {
    return 'compra';
  }

  protected function documentKind(): string {
    return 'compra';
  }

  protected function defaultPrefixCode(): string {
    return 'FC';
  }

  protected function defaultPrefixLabel(): string {
    return 'FC - Factura de compra';
  }

  protected function tracksStock(): bool {
    return FALSE;
  }

  protected function backRoute(): string {
    return 'saibher_bms_core.suppliers';
  }

  protected function backLabel(): string {
    return 'Volver a Proveedores';
  }

}

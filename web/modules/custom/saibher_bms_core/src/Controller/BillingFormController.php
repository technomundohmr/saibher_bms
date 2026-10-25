<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Controller;

/**
 * Renders the single-page sales invoice form (visual stage).
 *
 * Invoices customers: the shared document form is configured for the sale
 * mode, so the party card searches clients and the resulting document is an
 * invoice.
 */
final class BillingFormController extends DocumentFormControllerBase {

  protected function partyType(): string {
    return 'customer';
  }

  protected function pageTitle(): string {
    return 'Facturar';
  }

  protected function pageSubtitle(): string {
    return 'Formulario de facturación con cálculo automático de impuestos y totales.';
  }

  protected function icon(): string {
    return 'receipt_long';
  }

  protected function partyLabel(): string {
    return 'Cliente';
  }

  protected function documentTitle(): string {
    return 'Factura';
  }

  protected function prefixKeyword(): string {
    return 'venta';
  }

  protected function documentKind(): string {
    return 'venta';
  }

  protected function defaultPrefixCode(): string {
    return 'FV';
  }

  protected function defaultPrefixLabel(): string {
    return 'FV - Factura de venta';
  }

  protected function tracksStock(): bool {
    return TRUE;
  }

  protected function backRoute(): string {
    return 'saibher_bms_core.billing';
  }

  protected function backLabel(): string {
    return 'Volver a Facturas de venta';
  }

}

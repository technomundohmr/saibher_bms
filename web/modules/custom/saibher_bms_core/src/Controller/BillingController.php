<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Controller;

final class BillingController extends OperationalPageControllerBase {

  public function page(): array {
    // La acción principal va al formulario de facturación (página dedicada en
    // /bms/facturar) en lugar de abrir la modal de alta del documento.
    return $this->buildOperationalPage(
      'saibher_documents',
      'Facturas de venta',
      'Consulta los documentos transaccionales y registra nuevas ventas.',
      'receipt_long',
      'Nueva factura',
      NULL,
      NULL,
      NULL,
      NULL,
      NULL,
      '/bms/facturar',
    );
  }

}

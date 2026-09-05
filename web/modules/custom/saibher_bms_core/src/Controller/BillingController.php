<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Controller;

use Drupal\saibher_bms_core\Form\DocumentModalForm;

final class BillingController extends OperationalPageControllerBase {

  public function page(): array {
    return $this->buildOperationalPage(
      'saibher_documents',
      'Facturación',
      'Consulta los documentos transaccionales y registra nuevas ventas.',
      'receipt_long',
      'Nueva factura',
      DocumentModalForm::class,
    );
  }

}

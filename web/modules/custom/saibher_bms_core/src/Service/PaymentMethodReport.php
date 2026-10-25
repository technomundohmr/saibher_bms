<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Agrupa los pagos registrados por medio de pago dentro de un periodo.
 *
 * Cada medio de pago ya queda guardado uno a uno en los pagos del documento: un
 * párrafo «Pago» con su término de vocabulario y su importe. Este servicio solo
 * los suma y los ordena, para que el cierre del día o del mes se lea de una
 * sola vez. Deliberadamente no copia el importe al registro financiero: una
 * segunda copia quedaría desactualizada en cuanto se corrigiera un pago, y
 * además volvería a sumar la misma venta dentro de los totales del registro.
 *
 * La fecha que se usa es la del documento, que es la única que un pago tiene:
 * un pago no guarda su propio momento, hereda el de la factura que lo originó.
 */
final class PaymentMethodReport {

  /**
   * Content type de los documentos.
   */
  private const DOCUMENT_BUNDLE = 'document';

  /**
   * Tipo de párrafo que guarda un pago.
   */
  private const PAYMENT_BUNDLE = 'payment';

  /**
   * Vocabulario con los medios de pago.
   */
  private const PAYMENT_VOCABULARY = 'payment_method';

  /**
   * Etiqueta para los pagos guardados sin medio de pago reconocido.
   */
  public const UNLABELLED = 'Sin medio de pago';

  /**
   * Series de documento que se pueden filtrar en el reporte.
   *
   * El valor es el que cada documento guarda en field_document_type_document.
   */
  public const SERIES = [
    'todos' => 'Todas',
    'venta' => 'Factura de venta',
    'ticket' => 'Ticket',
    'compra' => 'Factura de compra',
  ];

  /**
   * Pagos listados en el detalle como máximo.
   *
   * El resumen siempre cubre todo el periodo; el detalle es una lista de
   * apoyo para revisar, así que se acota para no cargar una página enorme.
   */
  private const MAX_DETAIL = 500;

  /**
   * Decimales con los que se informa cada importe.
   */
  private const DECIMALS = 2;

  /**
   * Constructs the report.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   La conexión de base de datos.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Los almacenamientos de entidades.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   Los idiomas del sitio.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LanguageManagerInterface $languageManager,
  ) {
  }

  /**
   * Resumen y detalle de los pagos del periodo.
   *
   * @param int $from
   *   Momento inicial del periodo, como marca de tiempo.
   * @param int $to
   *   Momento final del periodo, como marca de tiempo.
   * @param string $series
   *   Clave de self::SERIES, o «todos».
   *
   * @return array{
   *   rows: array<int, array>,
   *   total: float,
   *   payments: int,
   *   documents: int,
   *   payments_detail: array<int, array>,
   *   truncated: bool
   * }
   *   Los totales por medio de pago, el total del periodo y el detalle.
   */
  public function report(int $from, int $to, string $series = 'todos'): array {
    $methods = $this->methods();
    $rows = [];
    $total = 0.0;
    foreach ($this->totals($from, $to, $series) as $row) {
      $id = (int) ($row->method ?? 0);
      $amount = round((float) $row->total, self::DECIMALS);
      $total += $amount;
      $rows[] = [
        'id' => $id,
        'method' => $methods[$id]['label'] ?? self::UNLABELLED,
        'order' => $methods[$id]['order'] ?? 99,
        'payments' => (int) $row->payments,
        'documents' => (int) $row->documents,
        'total' => $amount,
      ];
    }
    $total = round($total, self::DECIMALS);
    // El orden del punto de venta manda: efectivo primero, y al final lo que
    // no se pudo clasificar.
    usort($rows, static fn (array $a, array $b): int => [$a['order'], $b['method']] <=> [$b['order'], $a['method']]);
    foreach ($rows as &$row) {
      $row['share'] = $total > 0 ? round($row['total'] / $total * 100, 1) : 0.0;
      unset($row['order']);
    }
    unset($row);

    // Una sola fila con los totales del periodo, sin agrupar por medio.
    $totals = $this->totals($from, $to, $series, TRUE);
    $flat = reset($totals);
    $payments = $flat ? (int) $flat->payments : 0;
    $documents = $flat ? (int) $flat->documents : 0;

    return [
      'rows' => $rows,
      'total' => $total,
      'payments' => $payments,
      'documents' => $documents,
      'payments_detail' => $this->detail($from, $to, $series),
      'truncated' => $payments > self::MAX_DETAIL,
    ];
  }

  /**
   * Totales por medio de pago.
   *
   * @param int $from
   *   Momento inicial del periodo.
   * @param int $to
   *   Momento final del periodo.
   * @param string $series
   *   Clave de self::SERIES.
   * @param bool $flat
   *   Cuando es TRUE devuelve una sola fila con los totales del periodo sin
   *   agrupar, que es lo que se muestra en las cifras de arriba.
   *
   * @return array<int, array>
   *   Las filas agrupadas por medio de pago.
   */
  private function totals(int $from, int $to, string $series, bool $flat = FALSE): array {
    $query = $this->baseQuery($from, $to, $series);
    $query->addExpression('COUNT(*)', 'payments');
    $query->addExpression('COUNT(DISTINCT document.nid)', 'documents');
    if ($flat) {
      $query->addExpression('COALESCE(SUM(quantity.field_quantity_value), 0)', 'total');
      return $query->execute()->fetchAll();
    }
    $query->addField('method', 'field_method_target_id', 'method');
    $query->addExpression('COALESCE(SUM(quantity.field_quantity_value), 0)', 'total');
    $query->groupBy('method.field_method_target_id');
    return $query->execute()->fetchAll();
  }

  /**
   * Pagos del periodo, uno por uno, del más reciente al más antiguo.
   *
   * @param int $from
   *   Momento inicial del periodo.
   * @param int $to
   *   Momento final del periodo.
   * @param string $series
   *   Clave de self::SERIES.
   *
   * @return array<int, array>
   *   Los pagos con su documento, cliente, medio e importe.
   */
  private function detail(int $from, int $to, string $series): array {
    $langcode = $this->langcode();
    $query = $this->baseQuery($from, $to, $series);
    // Los datos del cliente y de la referencia son opcionales: un pago puede
    // no tener referencia, y una factura puede no tener cliente asignado.
    // Cada unión usa sus propios marcadores: si se repite uno, el último valor
    // sobrescribe al anterior y el cliente se deja de encontrar.
    $query->leftJoin('paragraph__field_reference', 'reference', 'reference.entity_id = payment.id AND reference.deleted = 0 AND reference.bundle = :payment_bundle AND reference.langcode = :payment_langcode', [':payment_bundle' => self::PAYMENT_BUNDLE, ':payment_langcode' => $langcode]);
    $query->leftJoin('node__field_customer_document', 'client', 'client.entity_id = document.nid AND client.deleted = 0 AND client.bundle = :document_bundle AND client.langcode = :document_langcode', [':document_bundle' => self::DOCUMENT_BUNDLE, ':document_langcode' => $langcode]);
    $query->leftJoin('node_field_data', 'customer', 'customer.nid = client.field_customer_document_target_id AND customer.default_langcode = 1');
    // addField() también devuelve el alias, así que cada columna se agrega por
    // separado y solo se encadenan las llamadas que sí devuelven la consulta.
    $query->addField('document', 'nid', 'document');
    $query->addField('document', 'title', 'title');
    $query->addField('document', 'created', 'created');
    $query->addField('series', 'field_document_type_document_value', 'series');
    $query->addField('method', 'field_method_target_id', 'method');
    $query->addField('quantity', 'field_quantity_value', 'value');
    $query->addField('customer', 'title', 'client_name');
    $query->addField('reference', 'field_reference_value', 'reference');
    $query->orderBy('document', 'created', 'DESC');
    $query->orderBy('document', 'title');
    $query->range(0, self::MAX_DETAIL);

    $methods = $this->methods();
    $rows = [];
    foreach ($query->execute() as $row) {
      $id = (int) ($row->method ?? 0);
      $rows[] = [
        'document' => (int) $row->document,
        'title' => (string) $row->title,
        'created' => (int) $row->created,
        'series' => (string) $row->series,
        'client' => trim((string) ($row->client_name ?? '')) ?: '—',
        'method' => $methods[$id]['label'] ?? self::UNLABELLED,
        'value' => round((float) $row->value, self::DECIMALS),
        'reference' => trim((string) ($row->reference ?? '')),
      ];
    }
    return $rows;
  }

  /**
   * Consulta base: los pagos de los documentos publicados del periodo.
   *
   * @param int $from
   *   Momento inicial del periodo.
   * @param int $to
   *   Momento final del periodo.
   * @param string $series
   *   Clave de self::SERIES.
   *
   * @return \Drupal\Core\Database\Query\Select
   *   La consulta, lista para agrupar o projectar.
   */
  private function baseQuery(int $from, int $to, string $series): Select {
    $langcode = $this->langcode();
    $query = $this->database->select('node__field_payment_document', 'payments');
    // Cada unión se llama por separado: Select::join() devuelve el alias que
    // le dio a la tabla, no la consulta.
    $query->join('paragraphs_item_field_data', 'payment', 'payment.id = payments.field_payment_document_target_id');
    $query->join('paragraph__field_quantity', 'quantity', 'quantity.entity_id = payment.id');
    $query->join('paragraph__field_method', 'method', 'method.entity_id = payment.id');
    $query->join('node_field_data', 'document', 'document.nid = payments.entity_id');
    $query->join('node__field_document_type_document', 'series', 'series.entity_id = document.nid');
    $query
      ->condition('payments.bundle', self::DOCUMENT_BUNDLE)
      ->condition('payments.deleted', 0)
      ->condition('payments.langcode', $langcode)
      ->condition('payment.type', self::PAYMENT_BUNDLE)
      ->condition('payment.parent_type', 'node')
      ->condition('quantity.bundle', self::PAYMENT_BUNDLE)
      ->condition('quantity.deleted', 0)
      ->condition('quantity.langcode', $langcode)
      ->condition('method.bundle', self::PAYMENT_BUNDLE)
      ->condition('method.deleted', 0)
      ->condition('method.langcode', $langcode)
      ->condition('series.bundle', self::DOCUMENT_BUNDLE)
      ->condition('series.deleted', 0)
      ->condition('series.langcode', $langcode)
      ->condition('document.type', self::DOCUMENT_BUNDLE)
      ->condition('document.status', 1)
      ->condition('document.default_langcode', 1)
      ->condition('document.langcode', $langcode)
      ->condition('document.created', $from, '>=')
      ->condition('document.created', $to, '<=');

    $key = isset(self::SERIES[$series]) && $series !== 'todos' ? $series : NULL;
    if ($key !== NULL) {
      $query->condition('series.field_document_type_document_value', self::SERIES[$key]);
    }
    return $query;
  }

  /**
   * Medios de pago del vocabulario, con su orden en el punto de venta.
   *
   * @return array<int, array{label: string, order: int}>
   *   Los términos indexados por identificador.
   */
  private function methods(): array {
    $methods = [];
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => self::PAYMENT_VOCABULARY]);
    foreach ($terms as $term) {
      $methods[(int) $term->id()] = [
        'label' => $term->label(),
        'order' => (int) $term->getWeight(),
      ];
    }
    return $methods;
  }

  /**
   * Idioma por defecto del sitio.
   *
   * @return string
   *   El código de idioma de los datos guardados.
   */
  private function langcode(): string {
    return $this->languageManager->getDefaultLanguage()->getId();
  }

}
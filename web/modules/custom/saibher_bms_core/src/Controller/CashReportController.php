<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Url;
use Drupal\saibher_bms_core\Service\PaymentMethodReport;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Página de caja: cuánto se cobró con cada medio de pago en un periodo.
 *
 * Responde la pregunta del cierre, del día o del mes: cuánto entró por efectivo,
 * cuánto por transferencia, Nequi o Daviplata. Los datos no se guardan otra vez,
 * se leen de los pagos que cada documento ya tiene.
 */
final class CashReportController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Periodos que se pueden elegir, con su etiqueta corta.
   */
  private const PERIODS = [
    'hoy' => 'Hoy',
    'ayer' => 'Ayer',
    'semana' => 'Esta semana',
    'mes' => 'Este mes',
    'mes_anterior' => 'Mes anterior',
    'personalizado' => 'Personalizado',
    'todo' => 'Todo',
  ];

  /**
   * Periodo con el que abre la página.
   */
  private const DEFAULT_PERIOD = 'hoy';

  public function __construct(
    protected readonly RequestStack $requestStack,
    protected readonly TimeInterface $time,
    protected readonly PaymentMethodReport $paymentMethods,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('request_stack'),
      $container->get('datetime.time'),
      $container->get(PaymentMethodReport::class),
    );
  }

  /**
   * Página del reporte de medios de pago.
   *
   * @return array
   *   El render array de la página.
   */
  public function page(): array {
    $request = $this->request();
    $zone = new \DateTimeZone(date_default_timezone_get());
    $today = (new \DateTimeImmutable('now', $zone))->setTime(0, 0);
    $endToday = $today->setTime(23, 59, 59);

    $period = (string) $request->query->get('periodo', self::DEFAULT_PERIOD);
    if (!isset(self::PERIODS[$period])) {
      $period = self::DEFAULT_PERIOD;
    }
    $series = (string) $request->query->get('serie', 'todos');

    [$from, $to, $label] = $this->range($period, $today, $endToday, $zone);
    $report = $this->paymentMethods->report($from, $to, $series);
    $series = isset(PaymentMethodReport::SERIES[$series]) ? $series : 'todos';

    return [
      '#theme' => 'saibher_bms_core_cash_report',
      '#title' => $this->t('Caja y finanzas'),
      '#subtitle' => $this->t('Cuánto se cobró con cada medio de pago, para el cierre del día o del mes.'),
      '#period_label' => $label,
      '#periods' => $this->chips('periodo', self::PERIODS, $period),
      '#series' => $this->chips('serie', PaymentMethodReport::SERIES, $series),
      '#range' => [
        'from' => (new \DateTimeImmutable('@' . $from))->setTimezone($zone)->format('Y-m-d'),
        'to' => (new \DateTimeImmutable('@' . $to))->setTimezone($zone)->format('Y-m-d'),
        'action' => Url::fromRoute('saibher_bms_core.cash')->toString(),
        'series' => $series,
      ],
      '#report' => $report,
      '#documents_label' => $report['documents'] === 1
        ? (string) $this->t('1 documento')
        : (string) $this->t('@count documentos', ['@count' => $report['documents']]),
      '#has_data' => $report['rows'] !== [],
      '#attached' => [
        'library' => ['saibher_bms_core/cash-report'],
      ],
      '#cache' => [
        'contexts' => ['url.query_args'],
        'tags' => ['node_list:document', 'taxonomy_term_list:payment_method'],
      ],
    ];
  }

  /**
   * Rango de fechas del periodo elegido, con su etiqueta para el encabezado.
   *
   * @param string $period
   *   Clave de self::PERIODS.
   * @param \DateTimeImmutable $today
   *   El día de hoy a las 00:00 en la zona del sitio.
   * @param \DateTimeImmutable $endToday
   *   El día de hoy a las 23:59:59 en la zona del sitio.
   * @param \DateTimeZone $zone
   *   La zona horaria del sitio.
   *
   * @return array{0: int, 1: int, 2: string}
   *   Momento inicial, momento final y etiqueta del periodo.
   */
  private function range(string $period, \DateTimeImmutable $today, \DateTimeImmutable $endToday, \DateTimeZone $zone): array {
    $yesterday = $today->modify('-1 day');
    $week = $today->modify('monday this week');
    $month = $today->modify('first day of this month');
    $previousMonth = $month->modify('-1 month');
    $previousMonthEnd = $previousMonth->modify('last day of this month')->setTime(23, 59, 59);

    switch ($period) {
      case 'ayer':
        return [$yesterday->getTimestamp(), $yesterday->setTime(23, 59, 59)->getTimestamp(), (string) $this->t('Ayer · @date', ['@date' => $yesterday->format('d/m/Y')])];

      case 'semana':
        return [$week->getTimestamp(), $endToday->getTimestamp(), (string) $this->t('Esta semana · @from – @to', ['@from' => $week->format('d/m/Y'), '@to' => $today->format('d/m/Y')])];

      case 'mes':
        return [$month->getTimestamp(), $endToday->getTimestamp(), (string) $this->t('Este mes · @from – @to', ['@from' => $month->format('d/m/Y'), '@to' => $today->format('d/m/Y')])];

      case 'mes_anterior':
        return [$previousMonth->getTimestamp(), $previousMonthEnd->getTimestamp(), (string) $this->t('Mes anterior · @from – @to', ['@from' => $previousMonth->format('d/m/Y'), '@to' => $previousMonthEnd->format('d/m/Y')])];

      case 'todo':
        return [0, $endToday->getTimestamp(), (string) $this->t('Todo el histórico')];

      case 'personalizado':
        $from = $this->day((string) $this->request()->query->get('desde', ''), $today, $zone);
        $to = $this->day((string) $this->request()->query->get('hasta', ''), $today, $zone)->setTime(23, 59, 59);
        // Al revés no es un periodo: se invierte para no mostrar una pantalla
        // vacía sin explicación.
        if ($from > $to) {
          [$from, $to] = [$to->setTime(0, 0), $from->setTime(23, 59, 59)];
        }
        return [$from->getTimestamp(), $to->getTimestamp(), (string) $this->t('@from – @to', ['@from' => $from->format('d/m/Y'), '@to' => $to->format('d/m/Y')])];
    }
    return [$today->getTimestamp(), $endToday->getTimestamp(), (string) $this->t('Hoy · @date', ['@date' => $today->format('d/m/Y')])];
  }

  /**
   * Convierte una fecha de la URL en el inicio de ese día.
   *
   * @param string $value
   *   La fecha en formato Y-m-d.
   * @param \DateTimeImmutable $fallback
   *   El día a usar cuando el valor no es una fecha válida.
   * @param \DateTimeZone $zone
   *   La zona horaria del sitio.
   *
   * @return \DateTimeImmutable
   *   El día a las 00:00 en la zona del sitio.
   */
  private function day(string $value, \DateTimeImmutable $fallback, \DateTimeZone $zone): \DateTimeImmutable {
    $day = $value !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone) : FALSE;
    return $day instanceof \DateTimeImmutable ? $day : $fallback;
  }

  /**
   * Enlaces de un grupo de filtros, marcando el que está activo.
   *
   * @param string $name
   *   El nombre del parámetro de la URL.
   * @param array<string, string> $options
   *   Clave y etiqueta de cada opción.
   * @param string $active
   *   La opción elegida.
   *
   * @return array<int, array>
   *   Los enlaces listos para la plantilla.
   */
  private function chips(string $name, array $options, string $active): array {
    $query = $this->request()->query->all();
    $chips = [];
    foreach ($options as $key => $label) {
      $query[$name] = $key;
      $chips[] = [
        'label' => $label,
        'url' => Url::fromRoute('saibher_bms_core.cash', [], ['query' => $query])->toString(),
        'active' => $key === $active,
      ];
    }
    return $chips;
  }

  /**
   * La petición actual, de donde salen los filtros de la URL.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   La petición que está renderizando la página.
   */
  private function request(): Request {
    return $this->requestStack->getCurrentRequest();
  }

}
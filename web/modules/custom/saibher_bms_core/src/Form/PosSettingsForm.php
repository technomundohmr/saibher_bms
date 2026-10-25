<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Form;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\saibher_bms_core\Service\DocumentNumberingInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Configuración general del sistema de punto de venta.
 *
 * Los valores se guardan como configuración del sitio (Config API,
 * `saibher_bms_core.settings`) y nunca como contenido ni entidades. Los
 * consumen el formulario de facturación (prefijos y consecutivos), la lógica
 * de ventas/inventario (impuestos, uso estricto de inventario y prohibición
 * de venta sin inventario) y el futuro catálogo web de productos.
 *
 * La interfaz replica las tarjetas de la vista de referencia de Saibher
 * (datos de la empresa, configuración de documentos, opciones generales y
 * plantillas de impresión) apoyándose en los componentes del tema
 * (`.sb-input`, `.sb-select`) y en tarjetas CSS propias del módulo.
 *
 * Comportamiento del número inicial (`numero_inicial_*`):
 * - Si se deja vacío, se respeta la numeración automática existente
 *   (máximo número emitido + 1 para ese tipo de documento).
 * - Si se define y ningún documento emitido tiene ese número o uno superior,
 *   se toma el número inicial tal cual.
 * - Si ya existe un documento con ese número o uno superior, la numeración
 *   automática manda y el valor se ignora (se muestra una advertencia al
 *   guardar), para no provocar saltos ni duplicados en el consecutivo.
 * El cálculo vive en `DocumentNumberingInterface`, no en el formulario.
 */
final class PosSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['saibher_bms_core.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'saibher_bms_core_pos_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('saibher_bms_core.settings');

    $form['#attributes']['class'][] = 'saibher-config-form';

    $form['empresa'] = [
      '#type' => 'details',
      '#title' => $this->t('Datos básicos de la empresa'),
      '#description' => $this->cardDescription($this->t('Aparecen en tus facturas y documentos.')),
      '#open' => TRUE,
      '#attributes' => ['class' => ['sb-card', 'sb-config-card']],
    ];
    $form['empresa']['nombre_empresa'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nombre de la empresa / Razón social'),
      '#default_value' => (string) $config->get('nombre_empresa'),
      '#required' => TRUE,
      '#attributes' => ['class' => ['sb-input']],
    ];
    $form['empresa']['contacto'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['sb-field-row', 'sb-field-row--2']],
    ];
    foreach (['direccion_empresa' => $this->t('Dirección'), 'telefono_empresa' => $this->t('Teléfono')] as $key => $label) {
      $form['empresa']['contacto'][$key] = [
        '#type' => 'textfield',
        '#title' => $label,
        '#default_value' => (string) $config->get($key),
        '#attributes' => ['class' => ['sb-input']],
      ];
    }

    $form['documentos'] = [
      '#type' => 'details',
      '#title' => $this->t('Configuración de documentos'),
      '#description' => $this->cardDescription($this->t('Prefijo y numeración de cada tipo de documento.')),
      '#open' => TRUE,
      '#attributes' => ['class' => ['sb-card', 'sb-config-card']],
    ];
    $form['documentos']['grupos'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['sb-config-group-grid']],
    ];
    foreach ($this->documentGroups() as $kind => $group) {
      $form['documentos']['grupos'][$kind] = [
        '#type' => 'details',
        '#title' => $group['title'],
        '#open' => TRUE,
        '#attributes' => ['class' => ['sb-config-subgroup']],
      ];
      $form['documentos']['grupos'][$kind]['campos'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['sb-field-row', 'sb-field-row--2']],
      ];
      $form['documentos']['grupos'][$kind]['campos']['prefijo_' . $kind] = [
        '#type' => 'select',
        '#title' => $this->t('Prefijo por defecto'),
        '#empty_value' => '',
        '#empty_option' => $this->t('— Sin prefijo —'),
        '#options' => $this->prefixOptions($group['type']),
        '#default_value' => (string) $config->get('prefijo_' . $kind),
        '#attributes' => ['class' => ['sb-select']],
      ];
      $form['documentos']['grupos'][$kind]['campos']['numero_inicial_' . $kind] = [
        '#type' => 'number',
        '#title' => $this->t('Número inicial (opcional)'),
        '#default_value' => $config->get('numero_inicial_' . $kind),
        '#min' => 1,
        '#step' => 1,
        '#attributes' => ['class' => ['sb-input'], 'placeholder' => $this->t('Ej. 1001')],
        '#description' => $this->t('Déjalo vacío para continuar la numeración automática.'),
      ];
    }

    $form['opciones'] = [
      '#type' => 'details',
      '#title' => $this->t('Opciones generales del sistema'),
      '#description' => $this->cardDescription($this->t('Activa solo lo que tu negocio necesita.')),
      '#open' => TRUE,
      '#attributes' => ['class' => ['sb-card', 'sb-config-card']],
    ];
    foreach ($this->switches() as $key => $option) {
      $form['opciones'][$key] = [
        '#type' => 'checkbox',
        '#title' => $option['title'],
        '#description' => $option['description'],
        '#default_value' => (bool) ($config->get($key) ?? FALSE),
        '#attributes' => [
          'class' => ['sb-config-switch', 'sb-checkbox'],
        ],
        '#wrapper_attributes' => ['class' => ['sb-config-switch-row']],
      ];
    }

    $form['impresion'] = [
      '#type' => 'details',
      '#title' => $this->t('Plantillas de impresión'),
      '#description' => $this->cardDescription($this->t('Personaliza cómo se ven tus facturas y tickets impresos.')),
      '#open' => TRUE,
      '#attributes' => ['class' => ['sb-card', 'sb-config-card']],
    ];
    if ($this->printTemplatesRouteExists()) {
      $form['impresion']['plantillas'] = [
        '#type' => 'link',
        '#title' => $this->t('Configurar plantillas de impresión'),
        '#url' => Url::fromRoute('saibher_bms_core.print_templates'),
        '#attributes' => ['class' => ['saibher-btn', 'saibher-btn--secondary']],
      ];
    }
    else {
      $form['impresion']['plantillas'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['sb-config-pending']],
        'nota' => [
          '#type' => 'markup',
          '#markup' => $this->t('La configuración de plantillas de impresión estará disponible próximamente.'),
        ],
      ];
    }

    $form = parent::buildForm($form, $form_state);
    $form['actions']['submit']['#value'] = $this->t('Guardar cambios');
    $form['actions']['submit']['#attributes']['class'][] = 'saibher-btn';
    $form['actions']['submit']['#attributes']['class'][] = 'saibher-btn--primary';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $labels = [
      'factura' => $this->t('factura'),
      'compra' => $this->t('compra'),
      'ticket' => $this->t('ticket'),
    ];
    foreach ($labels as $kind => $label) {
      $key = 'numero_inicial_' . $kind;
      $value = $form_state->getValue($this->valuePath($key));
      if ($value === '' || $value === NULL) {
        continue;
      }
      if (!is_numeric($value) || (int) $value < 1 || ((string) (int) $value) !== trim((string) $value)) {
        $form_state->setErrorByName($key, $this->t('El número inicial de @kind debe estar vacío o ser un número entero positivo.', ['@kind' => $label]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('saibher_bms_core.settings');

    foreach (array_keys($this->companyFields()) as $key) {
      $config->set($key, trim((string) $form_state->getValue($this->valuePath($key))));
    }
    foreach (array_keys($this->documentGroups()) as $kind) {
      $config->set('prefijo_' . $kind, trim((string) $form_state->getValue($this->valuePath('prefijo_' . $kind))));
    }
    foreach (array_keys($this->documentGroups()) as $kind) {
      $key = 'numero_inicial_' . $kind;
      $value = $form_state->getValue($this->valuePath($key));
      if ($value === '' || $value === NULL) {
        $config->clear($key);
      }
      else {
        $config->set($key, (int) $value);
      }
    }
    foreach (array_keys($this->switches()) as $key) {
      $config->set($key, (bool) $form_state->getValue($this->valuePath($key)));
    }

    $config->save();

    foreach ($this->initialNumberWarnings($form_state) as $warning) {
      $this->messenger()->addWarning($warning);
    }
    $this->messenger()->addStatus($this->t('Configuración del punto de venta guardada.'));
  }

  /**
   * Descripción de una tarjeta, marcada para los estilos de la vista.
   *
   * El tema de Drupal envuelve el `#description` de un `details` en un `<div>`
   * sin clase (details.html.twig), así que el texto se envuelve en un `<span>`
   * propio para poder separarlo del contenido con estilos.
   */
  private function cardDescription(TranslatableMarkup $text): FormattableMarkup {
    return new FormattableMarkup('<span class="sb-config-card__description">@text</span>', ['@text' => $text]);
  }

  /**
   * Campos de datos de la empresa mostrados en la primera tarjeta.
   */
  private function companyFields(): array {
    return [
      'nombre_empresa' => $this->t('Nombre de la empresa / Razón social'),
      'direccion_empresa' => $this->t('Dirección'),
      'telefono_empresa' => $this->t('Teléfono'),
    ];
  }

  /**
   * Tipos de documento con su prefijo de ejemplo.
   */
  private function documentGroups(): array {
    return [
      'factura' => [
        'title' => $this->t('Facturación'),
        'prefix' => 'FV',
        'type' => 'venta',
      ],
      'compra' => [
        'title' => $this->t('Compras'),
        'prefix' => 'FC',
        'type' => 'compra',
      ],
      'ticket' => [
        'title' => $this->t('Tickets'),
        'prefix' => 'TK',
        'type' => 'ticket',
      ],
    ];
  }

  /**
   * Opciones booleanas del sistema con su texto y descripción.
   */
  private function switches(): array {
    return [
      'usar_impuestos' => [
        'title' => $this->t('Usar impuestos'),
        'description' => $this->t('Aplica el impuesto configurado (ej. IVA) en facturas y compras.'),
      ],
      'generar_sitio_web_productos' => [
        'title' => $this->t('Generar sitio web de los productos'),
        'description' => $this->t('Publica automáticamente tu catálogo activo en una tienda en línea.'),
      ],
      'uso_estricto_inventario' => [
        'title' => $this->t('Uso estricto del inventario'),
        'description' => $this->t('Cada venta y compra descuenta o suma existencias de forma obligatoria.'),
      ],
      'prohibir_venta_sin_inventario' => [
        'title' => $this->t('Prohibir venta si falta inventario'),
        'description' => $this->t('No permite facturar un producto cuando no hay unidades suficientes.'),
      ],
    ];
  }

  /**
   * Ruta de un valor dentro del formulario.
   *
   * Las tarjetas se agrupan con `details` y `container`, que en el Form API no
   * anidan los valores de sus hijos: los `name` generados son planos
   * (`prefijo_factura`, no `documentos[factura][campos][prefijo_factura]`).
   * Por eso las lecturas y escrituras usan siempre la clave plana.
   */
  private function valuePath(string $key): array {
    return [$key];
  }

  /**
   * Opciones del selector de prefijo desde el vocabulario document_prefix.
   *
   * La clave es el código del prefijo (la parte anterior a " - " del rótulo);
   * el valor es el rótulo completo del término ("FV - Factura de venta").
   *
   * Cada término declara a qué tipo de documento pertenece en el campo
   * `field_prefix_document_type` (venta / compra / ticket), así que el selector
   * de cada grupo solo ofrece los prefijos de su tipo.
   */
  private function prefixOptions(string $type): array {
    $options = [];
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => 'document_prefix']);
    uasort($terms, static function (TermInterface $a, TermInterface $b): int {
      return strnatcasecmp($a->label(), $b->label());
    });
    foreach ($terms as $term) {
      if ($term->get('field_prefix_document_type')->value !== $type) {
        continue;
      }
      $options[saibher_bms_core_prefix_code($term)] = $term->label();
    }
    return $options;
  }

  /**
   * Indica si la ruta de plantillas de impresión ya está definida.
   */
  private function printTemplatesRouteExists(): bool {
    try {
      return (bool) \Drupal::service('router.route_provider')->getRouteByName('saibher_bms_core.print_templates');
    }
    catch (\Exception) {
      return FALSE;
    }
  }

  /**
   * Advertencias sobre los números iniciales guardados.
   *
   * El número inicial es la base del consecutivo mientras no exista ningún
   * documento emitido con ese número o uno superior. Si ya existe, la
   * numeración automática manda y se informa al usuario.
   */
  private function initialNumberWarnings(FormStateInterface $form_state): array {
    $labels = [
      'factura' => $this->t('factura'),
      'compra' => $this->t('compra'),
      'ticket' => $this->t('ticket'),
    ];
    $numbering = $this->numbering();
    $warnings = [];
    foreach ($labels as $kind => $label) {
      $initial = $form_state->getValue($this->valuePath('numero_inicial_' . $kind));
      if ($initial === '' || $initial === NULL) {
        continue;
      }
      $prefix = trim((string) $form_state->getValue($this->valuePath('prefijo_' . $kind)));
      if ($prefix === '') {
        $warnings[] = $this->t('El número inicial de @kind se omitió porque no hay prefijo configurado para ese tipo de documento. Asígnale un prefijo para que el valor se use como base del consecutivo.', ['@kind' => $label]);
        continue;
      }
      // La serie se mide por tipo de prefijo ('venta', 'compra', 'ticket'),
      // no por el código del prefijo configurado aquí.
      $series = $this->documentGroups()[$kind]['type'];
      $highest = $numbering->highest($series);
      if ($highest >= (int) $initial) {
        $warnings[] = $this->t('Ya existe un documento emitido con el número @initial o uno superior, así que la numeración automática manda: el próximo número de @kind será @next.', [
          '@initial' => $initial,
          '@kind' => $label,
          '@next' => $highest + 1,
        ]);
      }
    }
    return $warnings;
  }

  /**
   * Servicio de numeración de documentos.
   */
  private function numbering(): DocumentNumberingInterface {
    return \Drupal::service(DocumentNumberingInterface::class);
  }

}

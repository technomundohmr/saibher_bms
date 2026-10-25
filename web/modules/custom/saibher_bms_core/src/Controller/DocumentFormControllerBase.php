<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\saibher_bms_core\Service\DocumentNumberingInterface;
use Drupal\saibher_bms_core\Service\InventoryMovementsInterface;
use Drupal\saibher_bms_core\Service\PartyDirectoryInterface;
use Drupal\saibher_bms_core\Service\ProductPricingInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Renders the single-page document form (sales invoice or purchase order).
 *
 * The page is a front-end prototype: it feeds the configured document data
 * (product catalogue, prefixes, payment methods, collaborators) to the form so
 * it can be reviewed visually. The party (customer or supplier) is not shipped
 * with the page: the form asks the party directory service for the matches of
 * what the user types and then for the chosen record, so the browser never
 * receives the whole list.
 *
 * Choosing a party or adding a product autosaves the document as a draft of
 * the transactional content type: the script posts its state to the draft
 * route and the draft service turns it into a node, recalculating every amount
 * server side.
 * Only that service writes the node; this controller just hands over the URLs
 * and the CSRF token the browser needs.
 *
 * Each subclass decides whether the form invoices sales (customers) or
 * records purchases (suppliers) and provides the labels that the shared
 * template and script use to adapt the interface.
 *
 * The same page also edits what is already stored: the "Editar" action of the
 * documents table links here with the node ID of the row, and the script paints
 * the draft back into the form. Only then does the browser start writing again,
 * so opening a document never changes it.
 */
abstract class DocumentFormControllerBase extends ControllerBase {

  /**
   * Placeholder of the party ID inside the detail URL.
   *
   * Alphanumeric on purpose, so the URL generator leaves it untouched and the
   * script can replace it with the numeric ID of the chosen party.
   */
  private const PARTY_ID_PLACEHOLDER = 'PARTYID';

  /**
   * Value stored in field_document_type_document for each series.
   *
   * The list rows of a document that belongs to another series belong to the
   * other form, so the label decides which page can open them for editing.
   */
  private const DOCUMENT_TYPE_LABELS = [
    'venta' => 'Factura de venta',
    'compra' => 'Factura de compra',
  ];

  public function __construct(
    protected readonly RendererInterface $renderer,
    protected readonly PartyDirectoryInterface $partyDirectory,
    protected readonly DocumentNumberingInterface $numbering,
    protected readonly ProductPricingInterface $pricing,
    protected readonly InventoryMovementsInterface $inventory,
    protected readonly CsrfTokenGenerator $csrfToken,
    protected readonly RequestStack $requestStack,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('renderer'),
      $container->get(PartyDirectoryInterface::class),
      $container->get(DocumentNumberingInterface::class),
      $container->get(ProductPricingInterface::class),
      $container->get(InventoryMovementsInterface::class),
      $container->get('csrf_token'),
      $container->get('request_stack'),
    );
  }

  /**
   * Content type of the party: 'customer' (sale) or 'supplier' (purchase).
   */
  abstract protected function partyType(): string;

  /**
   * Page title (e.g. 'Facturar' or 'Comprar').
   */
  abstract protected function pageTitle(): string;

  /**
   * Page subtitle shown under the title.
   */
  abstract protected function pageSubtitle(): string;

  /**
   * Material symbol used as the page eyebrow icon.
   */
  abstract protected function icon(): string;

  /**
   * Party label: 'Cliente' or 'Proveedor'.
   */
  abstract protected function partyLabel(): string;

  /**
   * Document label capitalized: 'Factura' or 'Compra'.
   */
  abstract protected function documentTitle(): string;

  /**
   * Word used to filter the document prefixes ('venta' o 'compra').
   */
  abstract protected function prefixKeyword(): string;

  /**
   * Series this form issues: 'venta' (sale) or 'compra' (purchase).
   */
  abstract protected function documentKind(): string;

  /**
   * Default prefix code when the vocabulary has no matching prefix.
   */
  abstract protected function defaultPrefixCode(): string;

  /**
   * Default prefix label when the vocabulary has no matching prefix.
   */
  abstract protected function defaultPrefixLabel(): string;

  /**
   * Whether the lines of this form take units out of the inventory.
   *
   * A sales invoice does, and the form needs to know how much each product has
   * to warn (or refuse) a line that leaves the warehouse empty. A purchase adds
   * units instead, so its form leaves the inventory alone.
   */
  abstract protected function tracksStock(): bool;

  /**
   * Route the "back" button points to.
   */
  abstract protected function backRoute(): string;

  /**
   * Label of the "back" button.
   */
  abstract protected function backLabel(): string;

  /**
   * {@inheritdoc}
   */
  public function page(): array {
    return [
      '#theme' => 'saibher_bms_core_billing_form',
      '#title' => $this->pageTitle(),
      '#subtitle' => $this->pageSubtitle(),
      '#icon' => $this->icon(),
      '#back_route' => $this->backRoute(),
      '#back_label' => $this->backLabel(),
      '#party_label' => $this->partyLabel(),
      '#party_key' => mb_strtolower($this->partyLabel()),
      '#document_label' => mb_strtolower($this->documentTitle()),
      '#document_title' => $this->documentTitle(),
      '#attached' => [
        'library' => ['saibher_bms_core/billing-form'],
        'drupalSettings' => [
          'saibherBms' => [
            'billingForm' => $this->formData(),
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['user.permissions', 'url.query_args'],
      ],
    ];
  }

  /**
   * Builds the dataset consumed by the document form front end.
   *
   * The party is intentionally absent: it is resolved on demand through the
   * party directory service. The document is the exception: when the request
   * asks to edit one, its stored state travels with the page so the form opens
   * already filled in.
   */
  private function formData(): array {
    $storage = $this->entityTypeManager()->getStorage('node');

    $prefixes = [];
    $taxonomy = $this->entityTypeManager()->getStorage('taxonomy_term');
    $terms = $taxonomy->loadByProperties(['vid' => 'document_prefix']);
    $terms = $this->filterPrefixes($terms);
    // Los prefijos de un mismo tipo comparten consecutivo: lo decide el
    // servicio de numeración según la semilla configurada y lo emitido.
    $next = $this->numbering->next($this->prefixKeyword());
    foreach ($terms as $term) {
      $prefixes[] = [
        'id' => (int) $term->id(),
        'label' => $term->label(),
        'next' => $next,
      ];
    }
    if ($prefixes === []) {
      $prefixes[] = ['id' => 0, 'label' => $this->defaultPrefixLabel(), 'next' => $next];
    }

    $products = [];
    $catalogue = $storage->loadByProperties(['type' => 'product']);
    foreach ($catalogue as $node) {
      $products[] = [
        'id' => (int) $node->id(),
        'name' => $node->label(),
        'code' => (string) ($node->get('field_code')->value ?? ''),
        'sku' => (string) ($node->get('field_sku')->value ?? ''),
        'price' => $this->pricing->price($node),
        'taxPct' => $this->pricing->taxPct($node),
        'stock' => $this->inventory->stock($node),
      ];
    }

    $paymentMethods = [];
    $methods = $taxonomy->loadByProperties(['vid' => 'payment_method']);
    // El peso del término es el orden en que se ofrecen los medios de pago.
    uasort($methods, static function (TermInterface $a, TermInterface $b) {
      return [$a->getWeight(), $a->label()] <=> [$b->getWeight(), $b->label()];
    });
    foreach ($methods as $term) {
      $paymentMethods[] = $term->label();
    }

    $collaborators = [];
    foreach ($this->entityTypeManager()->getStorage('user')->loadByProperties(['status' => 1]) as $user) {
      if ($user->id() === 0) {
        continue;
      }
      $collaborators[] = [
        'id' => (int) $user->id(),
        'name' => $user->getDisplayName(),
      ];
    }

    return [
      'party' => $this->partyType(),
      'autocomplete' => [
        'search' => Url::fromRoute('saibher_bms_core.party_autocomplete', [
          'party' => $this->partyType(),
        ])->toString(),
        'detail' => Url::fromRoute('saibher_bms_core.party_detail', [
          'party' => $this->partyType(),
          'party_id' => self::PARTY_ID_PLACEHOLDER,
        ])->toString(),
        'minTerm' => [
          'name' => 2,
          'doc' => 3,
        ],
      ],
      'prefixes' => $prefixes,
      'products' => $products,
      'paymentMethods' => $paymentMethods,
      'collaborators' => $collaborators,
      'draft' => [
        'url' => Url::fromRoute('saibher_bms_core.draft_save', [
          'party' => $this->partyType(),
        ])->toString(),
        'closeUrl' => Url::fromRoute('saibher_bms_core.draft_close', [
          'party' => $this->partyType(),
        ])->toString(),
        // La ruta declara _csrf_request_header_token, así que Drupal valida
        // esta cabecera antes de dejar escribir el borrador.
        'csrfToken' => $this->csrfToken->get('rest'),
      ],
      // Con el interruptor encendido la interfaz no deja agregar una línea sin
      // existencias; apagado, avisa y deja decidir a quien vende.
      'strictStock' => $this->tracksStock() && $this->inventory->blocksUnstockedSales(),
      'partyLabel' => $this->partyLabel(),
      'partyKey' => mb_strtolower($this->partyLabel()),
      'documentLabel' => mb_strtolower($this->documentTitle()),
      'documentTitle' => $this->documentTitle(),
      'defaultPrefixCode' => $this->defaultPrefixCode(),
      'document' => $this->editableDocument(),
    ];
  }

  /**
   * Loads the document the "Editar" action of the table asked to open.
   *
   * That action points to this page with ?documento=<id> so the form shows the
   * stored draft instead of the Drupal node form. Only a document of this
   * series the current user may update is accepted; anything else resolves to
   * NULL and the form opens empty, exactly like a new document.
   *
   * @return array|null
   *   The document state for the form, or NULL when there is nothing to edit.
   */
  private function editableDocument(): ?array {
    $request = $this->requestStack->getCurrentRequest();
    $id = $request === NULL ? 0 : (int) $request->query->get('documento');
    if ($id <= 0) {
      return NULL;
    }
    $node = $this->entityTypeManager()->getStorage('node')->load($id);
    if (!$node instanceof NodeInterface
      || $node->bundle() !== 'document'
      || !$node->hasField('field_document_type_document')
      || $node->get('field_document_type_document')->value !== (self::DOCUMENT_TYPE_LABELS[$this->documentKind()] ?? NULL)
      || !$node->hasField('field_document_status')
      // Solo un borrador se edita: una factura cerrada tiene movimientos
      // definitivos y se cambia con la función de devoluciones.
      || $node->get('field_document_status')->value !== 'borrador'
      || !$node->access('view')
      || !$node->access('update')) {
      return NULL;
    }

    return [
      'id' => (int) $node->id(),
      'number' => (int) ($node->get('field_document_number')->value ?? 0),
      'prefixId' => (int) ($node->get('field_document_prefix')->first()?->target_id ?? 0),
      'partyId' => (int) ($node->get('field_customer_document')->first()?->target_id ?? 0),
      'items' => $this->documentItems($node),
      'payments' => $this->documentPayments($node),
    ];
  }

  /**
   * Lines stored in the document, ready to be painted back in the form.
   *
   * The line keeps the product by name (that is what the paragraph stores), so
   * the browser matches each line against the catalogue it already received.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The document being edited.
   *
   * @return array<int, array{name: string, quantity: float, unitValue: float, taxPct: float}>
   *   The stored lines, in display order.
   */
  private function documentItems(NodeInterface $node): array {
    $items = [];
    foreach ($node->get('field_document_item')->referencedEntities() as $paragraph) {
      if (!$paragraph instanceof ParagraphInterface) {
        continue;
      }
      $items[] = [
        'name' => (string) ($paragraph->get('field_product')->value ?? ''),
        'quantity' => (float) ($paragraph->get('field_quantity')->value ?? 0),
        'unitValue' => (float) ($paragraph->get('field_unit_value')->value ?? 0),
        'taxPct' => (float) ($paragraph->get('field_tax_percentage')->value ?? 0),
      ];
    }

    return $items;
  }

  /**
   * Payments stored in the document, with their method as a label.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The document being edited.
   *
   * @return array<int, array{method: string, value: float, reference: string}>
   *   The stored payments, in display order.
   */
  private function documentPayments(NodeInterface $node): array {
    $payments = [];
    foreach ($node->get('field_payment_document')->referencedEntities() as $paragraph) {
      if (!$paragraph instanceof ParagraphInterface) {
        continue;
      }
      $method = $paragraph->get('field_method')->first()?->entity;
      $payments[] = [
        'method' => $method instanceof TermInterface ? $method->label() : '',
        'value' => (float) ($paragraph->get('field_quantity')->value ?? 0),
        'reference' => (string) ($paragraph->get('field_reference')->value ?? ''),
      ];
    }

    return $payments;
  }

  /**
   * Filters the document prefixes to those matching the document type.
   *
   * Each term declares its type in `field_prefix_document_type`
   * (venta / compra / ticket), so the match uses that field. Terms without the
   * field, or from a vocabulary predating it, fall back to matching the
   * document keyword in the label; if nothing matches, every prefix is offered
   * so the form never ends up empty.
   *
   * @param \Drupal\taxonomy\TermInterface[] $terms
   *   All terms of the document_prefix vocabulary.
   *
   * @return \Drupal\taxonomy\TermInterface[]
   *   Los términos del tipo pedido.
   */
  private function filterPrefixes(array $terms): array {
    $keyword = $this->prefixKeyword();
    if ($keyword === '') {
      return $terms;
    }
    $byType = array_filter($terms, static function (TermInterface $term) use ($keyword): bool {
      return !$term->hasField('field_prefix_document_type')
        ? FALSE
        : $term->get('field_prefix_document_type')->value === $keyword;
    });
    if ($byType !== []) {
      return array_values($byType);
    }
    $byKeyword = array_filter($terms, static function (TermInterface $term) use ($keyword): bool {
      return mb_stripos($term->label(), $keyword) !== FALSE;
    });
    return $byKeyword !== [] ? array_values($byKeyword) : array_values($terms);
  }

}

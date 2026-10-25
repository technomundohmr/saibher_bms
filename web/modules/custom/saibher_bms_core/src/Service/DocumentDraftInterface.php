<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Service;

/**
 * Creates and updates the transactional document behind the billing form.
 *
 * The billing form is a single page that keeps its state in the browser. This
 * service is the only place that turns that state into a `document` node, so
 * the controller stays a thin adapter and the amounts stored in the database
 * are always calculated here instead of being trusted from the browser.
 */
interface DocumentDraftInterface {

  /**
   * Status key a freshly created draft is stored with.
   */
  public const STATUS_DRAFT = 'borrador';

  /**
   * Machine keys of the document status field, in lifecycle order.
   */
  public const STATUSES = [
    self::STATUS_DRAFT,
    'pendiente_pago',
    'pagada',
    'cerrada',
  ];

  /**
   * Creates the draft or applies the given state to the existing one.
   *
   * The number is assigned only when the node is created, so the draft reserves
   * it for as long as the document lives. Totals are recalculated from the
   * products, and the item and payment paragraphs are created, updated or
   * removed to match the payload exactly.
   *
   * @param string $kind
   *   Series the document belongs to: `venta`, `compra` or `ticket`.
   * @param array $payload
   *   Normalised document state. Recognised keys:
   *   - `documentId`: int, node to update, or 0 to create a new draft.
   *   - `partyType`: string, content type of the party (`customer`,
   *     `supplier`).
   *   - `partyId`: int, node ID of the party, or 0 when still unselected.
   *   - `prefixId`: int, document prefix term ID, or 0 when the vocabulary has
   *     no term.
   *   - `collaboratorId`: int, user the document is assigned to, or 0.
   *   - `items`: array of arrays with `product` (int product node ID),
   *     `quantity` (float) and `unitValue` (float).
   *   - `payments`: array of arrays with `method` (string label),
   *     `value` (float) and `reference` (string).
   *
   * @return array
   *   The saved state, with the keys `id`, `number`, `code`, `status`,
   *   `statusLabel`, `subtotal`, `taxTotal`, `total`, `items`, `stockWarnings`
   *   (products the configuration allowed to run out of) and `stock` (the units
   *   left of each product, keyed by node ID).
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   When the requested node is not an editable draft of the current user.
   * @throws \Drupal\saibher_bms_core\Exception\InsufficientStockException
   *   When the configuration forbids saving a document the inventory cannot
   *   cover. Nothing of this call stays written in that case.
   */
  public function save(string $kind, array $payload): array;

  /**
   * Closes the draft, after applying the state the browser sent.
   *
   * The document is saved first, so the movements of the last lines are
   * validated and written before the document stops being a draft. A closed
   * document gets the `pagada` status when its payments cover its total and
   * `pendiente_pago` otherwise; its movements stop being temporary.
   *
   * @param string $kind
   *   Series the document belongs to: `venta`, `compra` or `ticket`.
   * @param array $payload
   *   The same payload `save()` receives.
   *
   * @return array
   *   The saved state, with the same keys `save()` returns plus the final
   *   `status` and `statusLabel`.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   When the document is not an editable draft.
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When the document has no products or no party.
   */
  public function finalize(string $kind, array $payload): array;

  /**
   * Human labels of every status, keyed by machine key.
   *
   * @return array<string, string>
   *   Status labels.
   */
  public function statusLabels(): array;

}

<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Service;

use Drupal\node\NodeInterface;

/**
 * Keeps the stock of the catalogue in step with the documents.
 *
 * Adding a line to an invoice, changing its quantity or removing it has to be
 * reflected in the inventory without the user writing a single movement: this
 * service is the only place that creates, adjusts and reverses them.
 *
 * The movements are reconciled per product, so the browser can save the same
 * draft as many times as it wants and the result is always the same. A document
 * that is still a draft owns its movements: they change with the lines and go
 * away with them, and the stock they took is returned when the line is removed.
 * Once the document leaves the draft state its movements are never rewritten,
 * because they are already part of the inventory history; a later change is
 * recorded as a new movement of its own (a return gives the units back).
 */
interface InventoryMovementsInterface {

  /**
   * Movement type stored when a document leaves units in the stock.
   */
  public const TYPE_SALE = 'Salida por venta';

  /**
   * Movement type stored when units come back to the stock.
   */
  public const TYPE_RETURN = 'Entrada por devolución';

  /**
   * Reconciles the movements of a document with the quantities of its lines.
   *
   * The stock of every product involved is updated with the difference, and the
   * whole operation runs inside a transaction and a lock per product so two
   * invoices sold at the same time cannot take the same units.
   *
   * @param \Drupal\node\NodeInterface $document
   *   The document whose movements must match its lines.
   * @param array<int, float> $quantities
   *   Units of each product, keyed by product node ID. Repeated products are
   *   summed, and a product missing from the list is released.
   * @param string $reference
   *   Reference stored in the movements, normally the code of the document.
   *
   * @return array<int, array{name: string, available: float, requested: float}>
   *   Products left without units. The list is empty while the configuration
   *   forbids selling without stock, because in that case the shortage is
   *   refused instead of reported.
   *
   * @throws \Drupal\saibher_bms_core\Exception\InsufficientStockException
   *   When the configuration forbids leaving the stock below zero.
   */
  public function reconcile(NodeInterface $document, array $quantities, string $reference): array;

  /**
   * Units a product has in the inventory.
   *
   * @param \Drupal\node\NodeInterface $product
   *   The product to read.
   *
   * @return float
   *   The stock stored on the product.
   */
  public function stock(NodeInterface $product): float;

  /**
   * Units a document is holding for each product right now.
   *
   * The stock shown to the user already excludes what this document reserved, so
   * the form needs both numbers to know how many more units a line can take.
   *
   * @param \Drupal\node\NodeInterface $document
   *   The document whose movements to read.
   *
   * @return array<int, float>
   *   Reserved units keyed by product node ID, empty for a document that does
   *   not move the inventory.
   */
  public function reserved(NodeInterface $document): array;

  /**
   * Gives back the units a draft was holding and removes its movements.
   *
   * Called when a draft is deleted: its movements were only provisional, so the
   * warehouse has to get the units back instead of keeping them deducted for a
   * document that no longer exists.
   *
   * @param \Drupal\node\NodeInterface $document
   *   The draft that is being deleted.
   */
  public function releaseDraft(NodeInterface $document): void;

  /**
   * Whether the configuration forbids selling without stock.
   *
   * @return bool
   *   TRUE when a document may not take units the inventory does not have.
   */
  public function blocksUnstockedSales(): bool;

}
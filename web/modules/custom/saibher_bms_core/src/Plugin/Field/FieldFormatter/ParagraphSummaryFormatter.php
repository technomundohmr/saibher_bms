<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Renders a paragraph reference as the summary of its content.
 *
 * The entity label of a paragraph is administrative ("Orphaned Item de
 * documento: …" or "FV-00001 > Item de documento: …"). On a document page the
 * label of the field already says what the line is, so only the summary — the
 * product of a line, the method and reference of a payment — is shown.
 *
 * @FieldFormatter(
 *   id = "saibher_paragraph_summary",
 *   label = @Translation("Resumen del párrafo"),
 *   field_types = {
 *     "entity_reference",
 *   }
 * )
 */
class ParagraphSummaryFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];

    foreach ($items as $delta => $item) {
      $paragraph = $item->entity;
      $summary = $paragraph instanceof ParagraphInterface
        ? $paragraph->getSummary(['show_behavior_summary' => FALSE])
        : ($paragraph?->label() ?? '');

      $elements[$delta] = [
        '#type' => 'inline_template',
        '#template' => '{{ summary }}',
        '#context' => ['summary' => $summary],
      ];
    }

    return $elements;
  }

}
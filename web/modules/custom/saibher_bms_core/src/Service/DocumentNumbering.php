<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Consecutivo de documentos basado en la semilla de la configuración.
 *
 * Regla del número siguiente:
 * - Si hay un número inicial configurado y ningún documento emitido tiene ese
 *   número o uno superior, se toma el número inicial tal cual.
 * - En cualquier otro caso se toma el mayor número emitido más uno, para no
 *   repetir ni saltar números.
 *
 * Equivale a `max(semilla, mayor emitido + 1)`, que es la forma en que se
 * implementa para que las dos ramas de la regla queden explícitas.
 */
class DocumentNumbering implements DocumentNumberingInterface {

  /**
   * Tipo de contenido de los documentos emitidos.
   */
  private const BUNDLE_DOCUMENT = 'document';

  /**
   * Vocabulario de los prefijos.
   */
  private const VOCABULARY_PREFIX = 'document_prefix';

  /**
   * Campo con el número del documento.
   */
  private const FIELD_NUMBER = 'field_document_number';

  /**
   * Campo del documento que apunta a su prefijo.
   */
  private const FIELD_PREFIX = 'field_document_prefix';

  /**
   * Campo del prefijo que declara a qué tipo de documento pertenece.
   */
  private const FIELD_PREFIX_TYPE = 'field_prefix_document_type';

  /**
   * Semilla de configuración por tipo de documento.
   *
   * La clave del tipo (venta/compra/ticket) es la que usan los prefijos en
   * `field_prefix_document_type`; la de configuración es la que usa el
   * formulario de ajustes. No coinciden para las ventas, de ahí el mapa.
   *
   * @var array<string, string>
   */
  private const SETTINGS = [
    'venta' => 'numero_inicial_factura',
    'compra' => 'numero_inicial_compra',
    'ticket' => 'numero_inicial_ticket',
  ];

  /**
   * Construye el servicio de numeración.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function kinds(): array {
    return array_keys(self::SETTINGS);
  }

  /**
   * {@inheritdoc}
   */
  public function initial(string $kind): int {
    $this->assertKind($kind);
    $value = $this->configFactory->get('saibher_bms_core.settings')->get(self::SETTINGS[$kind]);

    return is_numeric($value) ? max(0, (int) $value) : 0;
  }

  /**
   * {@inheritdoc}
   */
  public function highest(string $kind): int {
    $this->assertKind($kind);

    $prefixes = $this->prefixTerms();
    $ofKind = [];
    $unattributed = [];
    foreach ($prefixes as $term) {
      $type = $term->hasField(self::FIELD_PREFIX_TYPE)
        ? (string) ($term->get(self::FIELD_PREFIX_TYPE)->value ?? '')
        : '';
      if ($type === $kind) {
        $ofKind[] = (int) $term->id();
      }
      elseif ($type === '') {
        // Prefijos antiguos, sin tipo declarado: no se pueden atribuir a una
        // serie, así que limitan a todas.
        $unattributed[] = (int) $term->id();
      }
    }

    $highest = $ofKind === []
      ? 0
      : $this->maxNumberForPrefixes($ofKind);
    $highest = max($highest, $this->maxNumberForPrefixes($unattributed));
    // Un documento sin prefijo tampoco se puede atribuir a ninguna serie.
    $highest = max($highest, $this->maxNumberWithoutPrefix());

    return $highest;
  }

  /**
   * {@inheritdoc}
   */
  public function next(string $kind): int {
    $initial = $this->initial($kind);
    $highest = $this->highest($kind);

    // Rama "número inicial": la serie está por debajo de la semilla, así que
    // el primer número emitido es el configurado.
    if ($initial > 0 && $highest < $initial) {
      return $initial;
    }

    // Rama "consecutivo": hay documentos con el mismo número o superiores, o
    // no hay semilla configurada.
    return $highest + 1;
  }

  /**
   * Mayor número entre los documentos que usan alguno de estos prefijos.
   *
   * @param int[] $term_ids
   *   IDs de los términos de prefijo.
   */
  private function maxNumberForPrefixes(array $term_ids): int {
    if ($term_ids === []) {
      return 0;
    }

    return $this->maxNumber([self::FIELD_PREFIX, $term_ids, 'IN']);
  }

  /**
   * Mayor número entre los documentos que no tienen prefijo asignado.
   */
  private function maxNumberWithoutPrefix(): int {
    return $this->maxNumber([self::FIELD_PREFIX, NULL, 'IS NULL']);
  }

  /**
   * Mayor número del conjunto de documentos que cumple una condición.
   *
   * La consulta ordena por número descendente y trae un solo resultado, así que
   * no carga todos los documentos emitidos.
   *
   * @param array{0: string, 1: mixed, 2: string} $condition
   *   Condición sobre el campo de prefijo.
   */
  private function maxNumber(array $condition): int {
    [$field, $value, $operator] = $condition;
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::BUNDLE_DOCUMENT)
      ->exists(self::FIELD_NUMBER)
      ->condition($field, $value, $operator)
      ->sort(self::FIELD_NUMBER, 'DESC')
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return 0;
    }
    $document = $storage->load(reset($ids));

    return $document instanceof NodeInterface
      ? (int) $document->get(self::FIELD_NUMBER)->value
      : 0;
  }

  /**
   * Términos del vocabulario de prefijos.
   *
   * @return \Drupal\taxonomy\TermInterface[]
   *   Prefijos declarados en el vocabulario.
   */
  private function prefixTerms(): array {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => self::VOCABULARY_PREFIX]);

    return array_filter($terms, static fn ($term): bool => $term instanceof TermInterface);
  }

  /**
   * Rechaza los tipos que no tienen numeración propia.
   *
   * @throws \InvalidArgumentException
   *   Si el tipo no está entre los gestionados.
   */
  private function assertKind(string $kind): void {
    if (!array_key_exists($kind, self::SETTINGS)) {
      throw new \InvalidArgumentException(sprintf('"%s" no tiene numeración propia.', $kind));
    }
  }

}

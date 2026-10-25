<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Service;

use Drupal\Core\Entity\EntityAccessControlHandlerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Directorio de partes respaldado por los tipos de contenido de partes.
 */
class PartyDirectory implements PartyDirectoryInterface {

  /**
   * Tipo de contenido de clientes.
   */
  private const BUNDLE_CUSTOMER = 'customer';

  /**
   * Tipo de contenido de proveedores.
   */
  private const BUNDLE_SUPPLIER = 'supplier';

  /**
   * Vocabulario con los tipos de identificación.
   */
  private const VOCABULARY_DOCUMENT_TYPE = 'person_document_type';

  /**
   * Campo con el número de identificación.
   *
   * La ortografía equivocada es el nombre real del campo en la base de datos;
   * renombrarlo es una migración aparte y queda fuera del alcance del
   * formulario. Es el mismo nombre en clientes y proveedores.
   */
  private const FIELD_DOCUMENT_NUMBER = 'field_document_nunber';

  /**
   * Campo con el nombre del contacto (mismo nombre en ambos bundles).
   */
  private const FIELD_CONTACT = 'field_person_name';

  /**
   * Campo con la dirección principal (mismo nombre en ambos bundles).
   */
  private const FIELD_MAIN_ADDRESS = 'field_main_address';

  /**
   * Campo con las sucursas como párrafos (mismo nombre en ambos bundles).
   */
  private const FIELD_LOCATIONS = 'field_locations';

  /**
   * Campo con el cupo de crédito, solo presente en clientes.
   */
  private const FIELD_CREDIT_LIMIT = 'field_credit_limit';

  /**
   * Término más corto aceptado al buscar por nombre.
   */
  private const MIN_TERM_NAME = 2;

  /**
   * Término más corto aceptado al buscar por número de identificación.
   */
  private const MIN_TERM_DOC = 3;

  /**
   * Multiplicador aplicado al límite en la búsqueda exacta.
   *
   * El número de identificación se compara exactamente en memoria, por eso la
   * consulta debe traer más candidatos que el número final de resultados.
   */
  private const EXACT_SEARCH_OVERFETCH = 5;

  /**
   * Construye el directorio de partes.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function bundles(): array {
    return [self::BUNDLE_CUSTOMER, self::BUNDLE_SUPPLIER];
  }

  /**
   * {@inheritdoc}
   */
  public function search(string $bundle, string $term, string $mode = 'name', int $limit = 10): array {
    $bundle = $this->assertBundle($bundle);
    $term = trim($term);
    $exact = $mode === 'doc';
    // En búsqueda por identificación se comparan los dígitos sin espacios, así
    // que el término se normaliza ANTES de la consulta: si no, un número
    // escrito con espacios no llegaría a candidarse en la base de datos.
    $needle = $exact ? $this->normalizeDocumentNumber($term) : $term;
    if (mb_strlen($needle) < ($exact ? self::MIN_TERM_DOC : self::MIN_TERM_NAME)) {
      return [];
    }

    $limit = max(1, $limit);
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', $bundle);

    if ($exact) {
      $query->condition(self::FIELD_DOCUMENT_NUMBER, $needle, 'CONTAINS');
    }
    else {
      $query->condition($query->orConditionGroup()
        ->condition('title', $needle, 'CONTAINS')
        ->condition(self::FIELD_DOCUMENT_NUMBER, $needle, 'CONTAINS')
        ->condition(self::FIELD_CONTACT, $needle, 'CONTAINS'));
    }

    $ids = $query->range(0, $exact ? $limit * self::EXACT_SEARCH_OVERFETCH : $limit)->execute();
    if ($ids === []) {
      return [];
    }

    // loadMultiple() no conserva el orden de la consulta, así que se recorre
    // la lista de IDs que sí lo está.
    $nodes = $storage->loadMultiple($ids);
    $matches = [];
    foreach ($ids as $id) {
      $node = $nodes[$id] ?? NULL;
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $number = $this->stringValue($node, self::FIELD_DOCUMENT_NUMBER);
      if ($exact && $this->normalizeDocumentNumber($number) !== $needle) {
        continue;
      }
      $matches[] = [
        'id' => (int) $node->id(),
        'label' => $node->label(),
        'suffix' => $number,
      ];
      if (count($matches) >= $limit) {
        break;
      }
    }

    return $matches;
  }

  /**
   * {@inheritdoc}
   */
  public function get(string $bundle, int $id): ?array {
    $bundle = $this->assertBundle($bundle);
    $storage = $this->entityTypeManager->getStorage('node');
    $node = $storage->load($id);
    if (!$node instanceof NodeInterface || $node->bundle() !== $bundle) {
      return NULL;
    }
    $access = $this->entityTypeManager->getAccessControlHandler('node');
    if (!$access instanceof EntityAccessControlHandlerInterface || !$access->access($node, 'view')) {
      return NULL;
    }

    $fields = $this->fields($bundle);
    $documentType = $this->documentType($node, $fields['documentType']);
    $options = $this->documentTypeOptions();
    // El tipo resuelto puede no estar en el vocabulario (clientes antiguos con
    // el tipo deducido), así que se añade para que el select nunca quede vacío.
    if ($documentType !== '' && !in_array($documentType, $options, TRUE)) {
      $options[] = $documentType;
      sort($options);
    }

    return [
      'id' => (int) $node->id(),
      'name' => $node->label(),
      'contact' => $this->stringValue($node, self::FIELD_CONTACT),
      'documentType' => $documentType,
      'documentTypeOptions' => $options,
      'documentNumber' => $this->stringValue($node, self::FIELD_DOCUMENT_NUMBER),
      'mainAddress' => $this->stringValue($node, self::FIELD_MAIN_ADDRESS),
      'branches' => $this->branches($node),
      'phone' => $this->stringValue($node, $fields['phone']),
      'email' => $this->stringValue($node, $fields['email']),
      'creditLimit' => (float) ($this->stringValue($node, self::FIELD_CREDIT_LIMIT) ?: 0),
    ];
  }

  /**
   * Campos cuyo nombre depende del tipo de contenido de la parte.
   *
   * @return array<string, string>
   *   Nombres de campo indexados por nombre lógico.
   */
  private function fields(string $bundle): array {
    $isSupplier = $bundle === self::BUNDLE_SUPPLIER;

    return [
      'documentType' => $isSupplier ? 'field_document_type_supplier' : 'field_document_type_customer',
      'phone' => $isSupplier ? 'field_phone_supplier' : 'field_phone_customer',
      'email' => $isSupplier ? 'field_email_supplier' : 'field_email_customer',
      'creditLimit' => self::FIELD_CREDIT_LIMIT,
    ];
  }

  /**
   * Sucursales (párrafo "Sucursal") declaradas por la parte.
   *
   * @return array<int, array{name: string, address: string}>
   *   Sucursales listas para el select de sede.
   */
  private function branches(NodeInterface $node): array {
    if (!$node->hasField(self::FIELD_LOCATIONS)) {
      return [];
    }
    $branches = [];
    foreach ($node->get(self::FIELD_LOCATIONS) as $item) {
      $paragraph = $item->entity;
      if ($paragraph === NULL || !$paragraph->hasField('field_name')) {
        continue;
      }
      $branches[] = [
        'name' => $paragraph->hasField('field_name')
          ? (string) ($paragraph->get('field_name')->value ?? '')
          : '',
        'address' => $paragraph->hasField('field_address')
          ? (string) ($paragraph->get('field_address')->value ?? '')
          : '',
      ];
    }

    return $branches;
  }

  /**
   * Tipo de identificación de la parte, deducido si no tiene término asignado.
   */
  private function documentType(NodeInterface $node, string $field): string {
    if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
      $term = $node->get($field)->first()?->entity;
      if ($term instanceof TermInterface) {
        return $term->label();
      }
    }
    $number = $this->stringValue($node, self::FIELD_DOCUMENT_NUMBER);

    return str_contains($number, '-') ? 'NIT' : 'Cédula de ciudadanía';
  }

  /**
   * Tipos de identificación disponibles para el formulario de documento.
   *
   * @return string[]
   *   Etiquetas del vocabulario de tipos de identificación.
   */
  private function documentTypeOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
      'vid' => self::VOCABULARY_DOCUMENT_TYPE,
    ]) as $term) {
      $options[] = $term->label();
    }
    sort($options);

    return $options;
  }

  /**
   * Lee un campo escalar como texto recortado.
   */
  private function stringValue(NodeInterface $node, string $field): string {
    if ($field === '' || !$node->hasField($field)) {
      return '';
    }
    $first = $node->get($field)->first();

    return trim((string) ($first?->value ?? ''));
  }

  /**
   * Normaliza un número de identificación para compararlo.
   */
  private function normalizeDocumentNumber(string $number): string {
    return (string) preg_replace('/\s+/u', '', $number);
  }

  /**
   * Rechaza cualquier cosa que no sea un tipo de contenido de parte.
   *
   * @throws \InvalidArgumentException
   *   Si el bundle es desconocido.
   */
  private function assertBundle(string $bundle): string {
    if (!in_array($bundle, $this->bundles(), TRUE)) {
      throw new \InvalidArgumentException(sprintf('"%s" is not a party content type.', $bundle));
    }

    return $bundle;
  }

}

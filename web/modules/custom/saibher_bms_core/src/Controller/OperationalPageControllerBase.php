<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFormBuilderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shared page builder for the Saibher operational tables.
 */
abstract class OperationalPageControllerBase extends ControllerBase {

  public function __construct(
    protected readonly EntityFormBuilderInterface $saibherEntityFormBuilder,
    protected readonly EntityTypeManagerInterface $saibherEntityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity.form_builder'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Builds a page from an existing View and a node add/edit form.
   *
   * The form shown in the modal is the real Drupal form for the given
   * content type bundle, so creation and editing reuse the standard node
   * forms (including any field_group grouping).
   *
   * @param string|null $bundle
   *   Bundle to build the node form for; NULL to omit the modal form.
   * @param int|null $nodeId
   *   Optional node id to render an edit form instead of a create form.
   * @param string|null $secondaryBundle
   *   Optional vocabulary machine name for the secondary modal form.
   * @param string|null $secondaryActionLabel
   *   Label of the secondary action/modal button.
   * @param string|null $modalVocabulary
   *   When set, the main modal shows a taxonomy term form for this vocabulary
   *   instead of a node form (used by the "Categorías" page).
   * @param string|null $actionUrl
   *   When set, the primary action renders as a link to this URL instead of
   *   opening the create modal.
   */
  protected function buildOperationalPage(
    string $viewId,
    string $title,
    string $subtitle,
    string $icon,
    string $actionLabel,
    ?string $bundle = NULL,
    ?int $nodeId = NULL,
    ?string $secondaryBundle = NULL,
    ?string $secondaryActionLabel = NULL,
    ?string $modalVocabulary = NULL,
    ?string $actionUrl = NULL,
  ): array {
    $view = Views::getView($viewId);
    if ($view === NULL) {
      throw new \RuntimeException(sprintf('The Saibher View "%s" could not be loaded.', $viewId));
    }

    $view->setDisplay('default');
    $table = $view->render();

    $modal_form = NULL;
    if ($modalVocabulary !== NULL) {
      $term_storage = $this->saibherEntityTypeManager->getStorage('taxonomy_term');
      $entity = $term_storage->create(['vid' => $modalVocabulary]);
      if ($entity !== NULL) {
        $modal_form = $this->saibherEntityFormBuilder->getForm($entity);
      }
    }
    elseif ($bundle !== NULL) {
      $node = $nodeId !== NULL
        ? Node::load($nodeId)
        : Node::create(['type' => $bundle]);
      if ($node !== NULL) {
        $modal_form = $this->saibherEntityFormBuilder->getForm($node);
      }
    }

    $secondary_modal_form = NULL;
    // The secondary modal is used for taxonomy vocabulary terms (e.g. the
    // "category" vocabulary), so the storage type is always taxonomy_term and
    // the passed value is the vocabulary (bundle) machine name.
    if ($secondaryBundle !== NULL) {
      $storage = $this->saibherEntityTypeManager->getStorage('taxonomy_term');
      $entity = $storage->create(['vid' => $secondaryBundle]);
      if ($entity !== NULL) {
        $secondary_modal_form = $this->saibherEntityFormBuilder->getForm($entity);
      }
    }

    return [
      '#theme' => 'saibher_bms_core_page',
      '#title' => $title,
      '#subtitle' => $subtitle,
      '#icon' => $icon,
      '#action_label' => $actionLabel,
      '#action_url' => $actionUrl,
      '#secondary_action_label' => $secondaryActionLabel,
      '#table' => $table,
      '#modal_form' => $modal_form,
      '#secondary_modal_form' => $secondary_modal_form,
      '#attached' => [
        'library' => ['saibher_bms_core/admin-pages'],
      ],
      '#cache' => [
        'contexts' => ['user.permissions', 'url.query_args'],
      ],
    ];
  }

}

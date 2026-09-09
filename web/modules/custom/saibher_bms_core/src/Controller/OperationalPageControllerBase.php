<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFormBuilderInterface;
use Drupal\node\Entity\Node;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shared page builder for the Saibher operational tables.
 */
abstract class OperationalPageControllerBase extends ControllerBase {

  public function __construct(
    protected readonly EntityFormBuilderInterface $saibherEntityFormBuilder,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity.form_builder'),
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
   */
  protected function buildOperationalPage(
    string $viewId,
    string $title,
    string $subtitle,
    string $icon,
    string $actionLabel,
    ?string $bundle = NULL,
    ?int $nodeId = NULL,
  ): array {
    $view = Views::getView($viewId);
    if ($view === NULL) {
      throw new \RuntimeException(sprintf('The Saibher View "%s" could not be loaded.', $viewId));
    }

    $view->setDisplay('default');
    $table = $view->render();

    $modal_form = NULL;
    if ($bundle !== NULL) {
      $node = $nodeId !== NULL
        ? Node::load($nodeId)
        : Node::create(['type' => $bundle]);
      if ($node !== NULL) {
        $modal_form = $this->saibherEntityFormBuilder->getForm($node);
      }
    }

    return [
      '#theme' => 'saibher_bms_core_page',
      '#title' => $title,
      '#subtitle' => $subtitle,
      '#icon' => $icon,
      '#action_label' => $actionLabel,
      '#table' => $table,
      '#modal_form' => $modal_form,
      '#attached' => [
        'library' => ['saibher_bms_core/admin-pages'],
      ],
      '#cache' => [
        'contexts' => ['user.permissions', 'url.query_args'],
      ],
    ];
  }

}

<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shared page builder for the Saibher operational tables.
 */
abstract class OperationalPageControllerBase extends ControllerBase {

  public function __construct(
    protected readonly FormBuilderInterface $saibherFormBuilder,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('form_builder'),
    );
  }

  /**
   * Builds a page from an existing View and a modal form.
   */
  protected function buildOperationalPage(
    string $viewId,
    string $title,
    string $subtitle,
    string $icon,
    string $actionLabel,
    string $formClass,
  ): array {
    $view = Views::getView($viewId);
    if ($view === NULL) {
      throw new \RuntimeException(sprintf('The Saibher View "%s" could not be loaded.', $viewId));
    }

    $view->setDisplay('default');
    $table = $view->render();

    return [
      '#theme' => 'saibher_bms_core_page',
      '#title' => $title,
      '#subtitle' => $subtitle,
      '#icon' => $icon,
      '#action_label' => $actionLabel,
      '#table' => $table,
      '#modal_form' => $this->saibherFormBuilder->getForm($formClass),
      '#attached' => [
        'library' => ['saibher_bms_core/admin-pages'],
      ],
      '#cache' => [
        'contexts' => ['user.permissions', 'url.query_args'],
      ],
    ];
  }

}

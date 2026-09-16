<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFormBuilderInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the taxonomy term edit form injected into the Saibher modal.
 */
final class TaxonomyTermFormController extends ControllerBase {

  public function __construct(
    protected readonly EntityFormBuilderInterface $saibherEntityFormBuilder,
    protected readonly RouteMatchInterface $saibherRouteMatch,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity.form_builder'),
      $container->get('current_route_match'),
    );
  }

  /**
   * Returns the edit form for an existing category inside the shared modal.
   */
  public function edit(TermInterface $taxonomy_term): AjaxResponse {
    if ($taxonomy_term->bundle() !== 'category') {
      throw new NotFoundHttpException();
    }

    $form = $this->saibherEntityFormBuilder->getForm($taxonomy_term);

    // El formulario entra por AJAX: sin una acción explícita, el núcleo deja
    // un placeholder que el JS reemplaza con la URL de la página actual, y el
    // envío reconstruiría el formulario en esa ruta (sin el parámetro del
    // término) creando duplicados. Se fija la acción de la ruta del panel.
    $form['#action'] = Url::fromRoute(
      'saibher_bms_core.term_edit_form',
      ['taxonomy_term' => $taxonomy_term->id()],
    )->setAbsolute()->toString();

    // Igual que en las modales de nodo del panel: se retira el chrome
    // administrativo de la modal de edición de categoría (opción de revisión).
    unset($form['revision']);

    return (new AjaxResponse())
      ->addCommand(new ReplaceCommand(
        '#saibher-modal [data-saibher-modal-edit]',
        [
          '#type' => 'container',
          '#attributes' => [
            'data-saibher-modal-edit' => '',
            'hidden' => 'hidden',
          ],
          'form' => $form,
        ],
      ));
  }

}
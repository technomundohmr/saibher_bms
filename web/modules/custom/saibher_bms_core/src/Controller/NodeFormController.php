<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFormBuilderInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Serves the node edit form that is injected into the Saibher modal.
 */
final class NodeFormController extends ControllerBase {

  public function __construct(
    protected readonly EntityFormBuilderInterface $saibherEntityFormBuilder,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity.form_builder'),
    );
  }

  /**
   * Returns the edit form for an existing node inside the shared modal.
   */
  public function edit(NodeInterface $node): AjaxResponse {
    $form = $this->saibherEntityFormBuilder->getForm($node);

    // El formulario entra por AJAX: sin una acción explícita, el núcleo deja
    // un placeholder que el JS reemplaza con la URL de la página actual, y el
    // envío reconstruiría el formulario en esa ruta (sin el parámetro del nodo)
    // creando duplicados. Se fija la acción de la ruta de edición del panel.
    $form['#action'] = Url::fromRoute(
      'saibher_bms_core.node_edit_form',
      ['node' => $node->id()],
    )->setAbsolute()->toString();

    // Igual que en el formulario de creación: se retira el chrome administrativo
    // (estado, autor, fecha, opciones de menú e información de la revisión) de la
    // modal de edición para las entidades del panel operativo que la usan.
    if (in_array($node->bundle(), ['product', 'customer', 'supplier'], true)) {
      unset($form['advanced'], $form['meta']);
    }

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
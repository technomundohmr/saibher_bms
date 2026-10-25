<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\saibher_bms_core\Service\PartyDirectoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Endpoints de autocompletado de la parte de un documento.
 *
 * No contienen lógica de negocio: traducen la petición HTTP y delegan en
 * Drupal\saibher_bms_core\Service\PartyDirectoryInterface.
 */
final class PartyAutocompleteController extends ControllerBase {

  /**
   * Máximo de coincidencias devueltas al formulario.
   */
  private const MAX_RESULTS = 10;

  /**
   * Constructs a PartyAutocompleteController.
   */
  public function __construct(
    private readonly PartyDirectoryInterface $partyDirectory,
  ) {
  }

  /**
   * Devuelve las coincidencias con lo que el usuario está escribiendo.
   */
  public function search(Request $request, string $party): JsonResponse {
    $term = (string) $request->query->get('q', '');
    $mode = (string) $request->query->get('mode', 'name');

    $results = $this->partyDirectory->search($party, $term, $mode, self::MAX_RESULTS);

    return $this->privateResponse(['results' => $results]);
  }

  /**
   * Devuelve la parte elegida para completar el formulario.
   */
  public function detail(string $party, string $party_id): JsonResponse {
    // La validación del ID se hace aquí y no en la ruta para poder generar la
    // URL con el marcador PARTYID que el script reemplaza por el ID real.
    if (!ctype_digit($party_id)) {
      throw new NotFoundHttpException();
    }
    $record = $this->partyDirectory->get($party, (int) $party_id);
    if ($record === NULL) {
      throw new NotFoundHttpException();
    }

    return $this->privateResponse(['party' => $record]);
  }

  /**
   * Envuelve la respuesta para que no quede en caché de intermediarios.
   */
  private function privateResponse(array $data): JsonResponse {
    $response = new JsonResponse($data);
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');

    return $response;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\saibher_bms_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\saibher_bms_core\Exception\InsufficientStockException;
use Drupal\saibher_bms_core\Service\DocumentDraftInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Recibe el autoguardado del formulario de documentos.
 *
 * Es un adaptador sin reglas de negocio: traduce la petición y entrega el
 * estado a Drupal\saibher_bms_core\Service\DocumentDraftInterface, que decide
 * qué nodo se crea o actualiza y recalcula los importes.
 */
final class DocumentDraftController extends ControllerBase {

  /**
   * Motivos por los que una factura no se puede cerrar, en el idioma del mostrador.
   *
   * El servicio razona en inglés porque son excepciones internas; quien está
   * cobrando necesita saber qué falta, no leer una traza.
   */
  private const CLOSE_REASONS = [
    'A document without products cannot be closed.' => [
      'customer' => 'Agrega al menos un producto antes de cerrar la factura.',
      'supplier' => 'Agrega al menos un producto antes de cerrar la factura de compra.',
    ],
    'A document without a party cannot be closed.' => [
      'customer' => 'Elige un cliente antes de cerrar la factura.',
      'supplier' => 'Elige un proveedor antes de cerrar la factura.',
    ],
    'The party of the document no longer exists.' => [
      'customer' => 'El cliente elegido ya no existe. Elige otro.',
      'supplier' => 'El proveedor elegido ya no existe. Elige otro.',
    ],
  ];

  /**
   * Constructs a DocumentDraftController.
   */
  public function __construct(
    private readonly DocumentDraftInterface $draft,
  ) {
  }

  /**
   * Crea el borrador o aplica al existente el estado enviado.
   *
   * La serie se deduce de la parte del documento: una factura de venta a un
   * cliente es `venta` y una compra a un proveedor es `compra`, que es
   * exactamente como el formulario ya etiqueta sus prefijos.
   */
  public function save(Request $request, string $party): JsonResponse {
    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      throw new BadRequestHttpException('The request body must be a JSON object.');
    }

    try {
      $result = $this->draft->save($this->kind($party), $payload);
    }
    catch (InsufficientStockException $exception) {
      return $this->unprocessable($exception);
    }

    return $this->respond($result);
  }

  /**
   * Cierra el documento: guarda lo que se está viendo y deja de ser borrador.
   *
   * El estado final lo decide el servicio a partir de lo cobrado, así que el
   * botón «Cerrar e imprimir» no necesita saber nada: cierra lo que hay y deja
   * que la factura quede pendiente de pago o pagada según corresponda.
   */
  public function close(Request $request, string $party): JsonResponse {
    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      throw new BadRequestHttpException('The request body must be a JSON object.');
    }

    try {
      $result = $this->draft->finalize($this->kind($party), $payload);
    }
    catch (InsufficientStockException $exception) {
      return $this->unprocessable($exception);
    }
    catch (BadRequestHttpException $exception) {
      return $this->badRequest($exception->getMessage(), $party);
    }

    return $this->respond($result);
  }

  /**
   * Respuesta del adaptador: privada y sin caché, como el autoguardado.
   *
   * @param array $result
   *   The state returned by the draft service.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response for the browser.
   */
  private function respond(array $result): JsonResponse {
    $response = new JsonResponse($result);
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');

    return $response;
  }

  /**
   * Traduce un inventario insuficiente a la respuesta que espera el formulario.
   *
   * El 422 no es un fallo del servidor: el documento no se guardó porque no hay
   * existencias y la configuración no permite vender sin ellas. El cuerpo lleva
   * el detalle para que la interfaz nombre cada producto y su disponible.
   *
   * @param \Drupal\saibher_bms_core\Exception\InsufficientStockException $exception
   *   The refusal raised by the inventory service.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A 422 response listing the products without stock.
   */
  private function unprocessable(InsufficientStockException $exception): JsonResponse {
    $response = new JsonResponse([
      'message' => 'No hay inventario suficiente para guardar esta factura.',
      'shortages' => $exception->shortages(),
    ], 422);
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');

    return $response;
  }

  /**
   * Traduce una petición que no se puede atender a una respuesta del formulario.
   *
   * @param string $reason
   *   El motivo interno del servicio.
   * @param string $party
   *   Tipo de parte de la ruta.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Un 400 con el motivo listo para mostrar.
   */
  private function badRequest(string $reason, string $party): JsonResponse {
    $reasons = self::CLOSE_REASONS[$reason] ?? [];
    $response = new JsonResponse([
      'message' => $reasons[$party] ?? $reasons['customer'] ?? 'No se pudo cerrar la factura.',
    ], 400);
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');

    return $response;
  }

  /**
   * Serie que corresponde a cada tipo de parte.
   */
  private function kind(string $party): string {
    return $party === 'supplier' ? 'compra' : 'venta';
  }

}

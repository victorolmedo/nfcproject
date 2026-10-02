<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pos;

use App\Enums\TransactionSource;
use App\Exceptions\RendersApiError;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Pos\EarnRequest;
use App\Http\Resources\TransactionResource;
use App\Services\Loyalty\PointsService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Credito de puntos por una compra.
 *
 * El controlador no calcula nada: traduce una peticion HTTP a una llamada de
 * `PointsService` y deja que las reglas de negocio decidan. Toda la logica de
 * puntos (tope diario, redondeo, minimo de compra, vigencia del programa) vive en
 * el servicio y esta cubierta por sus tests. Lo unico que se resuelve aqui es lo que
 * es genuinamente de HTTP: cabecera de idempotencia, forma de la respuesta y
 * traduccion de excepciones a codigos de estado.
 */
class EarnController extends ApiController
{
    public function __construct(private readonly PointsService $points) {}

    public function __invoke(EarnRequest $request): JsonResponse
    {
        try {
            $transaction = $this->points->earn(
                $request->customerId(),
                $request->amountMinor(),
                $request->currency(),
                $request->idempotencyKey(),
                operatorId: $request->user()->getKey(),
                source: TransactionSource::PosScanClient,
                externalRef: $request->externalRef(),
                isVisit: $request->isVisit(),
            );
        } catch (RendersApiError $exception) {
            return $this->fromDomainError($exception);
        } catch (ModelNotFoundException) {
            /*
             * El cliente se valida con `exists` en el FormRequest, asi que llegar aqui
             * significa que desaparecio entre la validacion y la escritura. Es una
             * carrera legitima: el cajero escanea una tarjeta y alguien borra al
             * cliente en ese instante. Se responde 404, no 500, porque para el POS es
             * una tarjeta que ya no existe y no un fallo del servidor.
             */
            return $this->error(
                'resource.not_found',
                'El cliente no existe.',
                Response::HTTP_NOT_FOUND,
            );
        }

        /*
         * 201 y no 200: se ha creado una transaccion contable. En el reintento con la
         * misma clave se devuelve la original, y tambien 201, porque el POS trata
         * ambos casos igual: la operacion quedo registrada una vez.
         */
        return $this->success(new TransactionResource($transaction), Response::HTTP_CREATED);
    }
}

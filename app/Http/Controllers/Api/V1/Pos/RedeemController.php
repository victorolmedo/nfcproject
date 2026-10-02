<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pos;

use App\Enums\TransactionSource;
use App\Exceptions\RendersApiError;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Pos\RedeemRequest;
use App\Http\Resources\RedemptionCodeResource;
use App\Http\Resources\TransactionResource;
use App\Models\RedemptionCode;
use App\Models\Reward;
use App\Services\Loyalty\PointsService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Canje de un premio por puntos.
 *
 * Devuelve tres cosas porque el POS necesita las tres para cerrar la operacion sin
 * una segunda llamada: la transaccion (lo que se desconto), el codigo (lo que se
 * lleva el cliente) y el stock que queda del premio (para offertar el siguiente
 * premio o avisar al encargado). Con la ultima unidad, el POS puede reaccionar sin
 * esperar al siguiente escaneo.
 */
class RedeemController extends ApiController
{
    public function __construct(private readonly PointsService $points) {}

    public function __invoke(RedeemRequest $request): JsonResponse
    {
        try {
            /*
             * El premio se resuelve dentro del `try` a proposito.
             *
             * `PointsService::redeem()` trabaja sobre el modelo y vuelve a bloquear la
             * fila por su cuenta, que es donde se lee el inventario con garantia. Si se
             * pasara el modelo del request, el servicio tendria que confiar en unos
             * datos que el cajero pudo enviar. Aqui solo se localiza por codigo, y
             * `Rule::exists` ya ha garantizado que pertenece a este tenant.
             */
            $reward = Reward::query()
                ->where('code', $request->rewardCode())
                ->firstOrFail();

            $transaction = $this->points->redeem(
                $request->customerId(),
                $reward,
                $request->idempotencyKey(),
                operatorId: $request->user()->getKey(),
                source: TransactionSource::PosScanClient,
                externalRef: $request->externalRef(),
                notes: $request->notes(),
            );
        } catch (RendersApiError $exception) {
            return $this->fromDomainError($exception);
        } catch (ModelNotFoundException) {
            return $this->error(
                'resource.not_found',
                'El cliente o el premio no existe.',
                Response::HTTP_NOT_FOUND,
            );
        }

        /*
         * El codigo y el stock se releen DESPUES de la llamada, ya con el stock
         * descontado dentro de la transaccion ya confirmada.
         *
         * La relacion es 1:1 con la transaccion de canje, y se busca por esa transaccion
         * en lugar de "el ultimo codigo del cliente": si el POS reintenta y el
         * servicio devuelve la transaccion original, este codigo sigue siendo el suyo
         * y no el de un canje posterior.
         */
        $code = RedemptionCode::query()
            ->where('transaction_id', $transaction->getKey())
            ->sole();

        $reward->refresh();

        return $this->success([
            'transaction' => new TransactionResource($transaction),
            'redemption_code' => new RedemptionCodeResource($code),
            'reward' => [
                'code' => $reward->code,
                'title' => $reward->title,
                'points_required' => (int) $reward->points_required,

                /*
                 * `inventory: null` significa ilimitado, y se devuelve tal cual en
                 * lugar de convertirlo en un numero. Si el POS recibiera un `0`
                 * interpretaria que se agotó, y el premio sigue disponible. Que el
                 * cliente distinga "no queda" de "no hay control" es decision suya.
                 */
                'inventory' => $reward->inventory,
                'redemptions_count' => (int) $reward->redemptions_count,
            ],
        ], Response::HTTP_CREATED);
    }
}

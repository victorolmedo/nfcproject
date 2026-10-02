<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\RendersApiError;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base de los controllers de la API.
 *
 * Centraliza el sobre de respuesta para que el POS tenga siempre la misma forma
 * y para que anadir un endpoint no requiera decidir, de nuevo, si la respuesta
 * viene envuelta o no.
 */
abstract class ApiController extends Controller
{
    /**
     * @param  array<string, mixed>  $meta
     */
    protected function success(mixed $data = null, int $status = Response::HTTP_OK, array $meta = []): Response
    {
        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    protected function error(string $code, string $message, int $status, array $details = []): Response
    {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json(['error' => $error], $status);
    }

    /**
     * Traduce una excepcion de dominio a una respuesta HTTP.
     *
     * Cada error de negocio implementa RendersApiError, asi que agregar uno nuevo
     * no requiere tocar ningun controller ni el renderer global.
     */
    protected function fromDomainError(RendersApiError $exception): Response
    {
        return $this->error(
            $exception->errorCode(),
            $exception->getMessage(),
            $exception->errorStatus(),
            $exception->errorDetails(),
        );
    }
}

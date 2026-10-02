<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Cabecera y normalizacion de la clave de idempotencia.
 *
 * Existe como clase y no como constante suelta porque la clave se usa en al menos
 * tres sitios (validacion, servicio y controlador) y las tres tienen que aplicar
 * exactamente la misma normalizacion. Si cada una normalizara por su cuenta,
 * `ABC` y `abc` se tratarian como operaciones distintas: el POS reintentaria, el
 * motor no reconoceria la repeticion y cobraria dos veces. Ese es el fallo exacto
 * que la idempotencia existe para evitar, asi que la normalizacion vive en un
 * unico sitio.
 */
final class IdempotencyKey
{
    public const HEADER = 'Idempotency-Key';

    /**
     * Tope de longitud.
     *
     * NO es un limite arbitrario: `transactions.idempotency_key` es `varchar(128)`.
     * Si aqui se admitiese mas, una clave de 150 caracteres pasaria la validacion
     * y reventaria contra la base de datos con un error crudo de PostgreSQL en vez
     * de un 422 que diga cual es el campo culpable. El limite tiene que coincidir
     * con la columna, y si esta cambia hay que cambiar el dos.
     *
     * 128 caracteres cubren de sobra cualquier clave que generen un POS o un proxy,
     * y dejan margen para claves compuestas del estilo
     * `TERMINAL-03:2026-06-15:000481`.
     */
    public const MAX_LENGTH = 128;

    /**
     * Caracteres admitidos.
     *
     * Sin espacios ni caracteres de control: la clave acaba en un indice unico y en
     * los logs de las transacciones, y un valor con saltos de linea permitiria
     * inyectar lineas falsas en el log de auditoria. Se acepta el rango ASCII
     * imprimible.
     */
    public const PATTERN = '/^[\x21-\x7E]+$/';

    /**
     * Reduce la clave a su forma canonica.
     *
     * Recortar y pasar a mayusculas hace que el POS que manda `PREM-001` y el que
     * manda ` prem-001 ` converjan en la misma clave. El recorte es importante por
     * si solo: una peticion reintentada suele enviar la cabecera con un espacio
     * adventicio que no estaba en el primer intento.
     */
    public static function normalize(string $key): string
    {
        return mb_strtoupper(trim($key));
    }
}

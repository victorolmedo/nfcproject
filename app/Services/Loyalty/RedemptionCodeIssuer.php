<?php

declare(strict_types=1);

namespace App\Services\Loyalty;

use App\Models\Reward;
use Illuminate\Support\Str;

/**
 * Emite el codigo canjeable que respalda un canje de puntos.
 *
 * Existe como servicio aparte y no como metodo del modelo porque generar el codigo
 * es una operacion con dos garantias que conviene tener juntas y en un solo sitio:
 * que el codigo sea criptograficamente impredecible y que el hash sea unico entre
 * todos los tenants.
 *
 * --- Sobre el alfabeto ---
 *
 * Se excluyen `0/O`, `1/I/L`, `U` y `B`. No es estetica: estos codigos los teclea
 * un cajero de cara al cliente o los lee por telefono, y las transcripciones
 * equivocadas (`0` por `O`, `1` por `I`) son la causa habitual de que un premio
 * valido se rechace. Un alfabeto de 30 caracteres sigue dejando 30^12
 * combinaciones, de sobra para el volumen de un comercio.
 *
 * --- Por que se guardan el codigo y su hash ---
 *
 * El texto plano es lo que el cajero muestra y el cliente gasta. El hash es lo que
 * permite buscar sin exponer los codigos vivos en caso de una filtracion de la
 * tabla. Se guardan ambos porque un codigo que no se puede mostrar no sirve de
 * nada. `code_hash` es UNIQUE, y eso es lo que impide que dos canjes generen el
 * mismo codigo.
 */
final class RedemptionCodeIssuer
{
    /** Longitud del codigo. 12 caracteres de un alfabeto de 30. */
    public const CODE_LENGTH = 12;

    /**
     * Alfabeto sin caracteres que se confunden al transcribir.
     *
     * @var list<string>
     */
    private const ALPHABET = [
        'A', 'C', 'D', 'E', 'F', 'G', 'H', 'J', 'K', 'M',
        'N', 'P', 'Q', 'R', 'S', 'T', 'V', 'W', 'X', 'Y',
        'Z', '2', '3', '4', '5', '6', '7', '8', '9',
    ];

    /**
     * Prefijo del codigo, para que un cajero reconozca de un vistazo que es un
     * premio y no un codigo de descuento.
     *
     * El prefijo tambien respeta el alfabeto: `AUR` lleva una `U`, que es justo uno de los
     * caracteres que este servicio existe para evitar. Se eligio `PREM` y no una sigla
     * mas corta para no repetir el problema en el resto del codigo.
     */
    private const PREFIX = 'PREM-';

    /**
     * Emite el codigo de un canje ya contabilizado.
     *
     * @return array{code: string, code_hash: string, expires_at: \DateTimeInterface}
     */
    public function issue(Reward $reward): array
    {
        $code = $this->generate();

        // `redemption_valid_days` es NOT NULL en el esquema y define hasta cuando
        // el cliente puede usar el premio. Si el catalogo no define nada, se usa un
        // plazo corto y conservador: un codigo sin caducidad es una pasivo que el
        // comercio acaba absorbiendo.
        $days = max(1, (int) $reward->redemption_valid_days);

        return [
            'code' => $code,
            'code_hash' => self::hash($code),
            'expires_at' => now()->addDays($days),
        ];
    }

    /**
     * Hash de un codigo.
     *
     * El indice unico de `code_hash` es la garantia real contra colisiones entre
     * tenants. Con SHA-256 en hexadecimal son 64 caracteres, que es exactamente lo
     * que permite la columna.
     */
    public static function hash(string $code): string
    {
        return hash('sha256', strtoupper(trim($code)));
    }

    /**
     * Genera un codigo aleatorio criptograficamente seguro.
     *
     * `random_int` y no `rand` ni `mt_rand`: estos ultimos no son seguros y el
     * alfabeto reducido los hace todavia mas predecibles. Un codigo adivinable
     * significa que cualquiera puede canjear premios ajenos.
     *
     * @throws \RuntimeException si el generador no puede ofrecer entropia segura.
     */
    private function generate(): string
    {
        $alphabetSize = count(self::ALPHABET);
        $body = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $body .= self::ALPHABET[random_int(0, $alphabetSize - 1)];
        }

        return self::PREFIX.$body;
    }

    /**
     * Valor a persistir en `value_minor` / `value_percent`.
     *
     * Un premio puede ser un descuento fijo, un porcentaje o un producto sin
     * valor monetario, y cada caso deja columnas distintas informadas. Centralizarlo
     * aqui evita que el servicio invente valores por su cuenta.
     *
     * @return array{value_minor: ?int, value_percent: ?string}
     */
    public static function valueFor(Reward $reward): array
    {
        return [
            'value_minor' => $reward->value_minor,
            'value_percent' => $reward->value_percent,
        ];
    }

    /**
     * Normaliza un codigo escrito por un humano antes de buscarlo.
     */
    public static function normalize(string $code): string
    {
        return Str::upper(trim($code));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Loyalty;

use App\Enums\LedgerEntryType;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\PointLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * Decide cuantos puntos gana un cliente en una operacion.
 *
 * Es la capa que SI conoce la base de datos: resuelve la configuracion vigente del
 * tenant, calcula lo que el cliente ya gano hoy y se lo pasa a `PointCalculator`,
 * que es puro. La separacion es deliberada: toda la aritmetica queda testeable
 * sin montar transacciones, y aqui solo queda el "leer el mundo".
 *
 * --- Por que el tope diario cuenta solo lo ACUMULADO ---
 *
 * `earnedTodayFor` suma unicamente asientos de tipo `earn`, `adjust` y `reverse`.
 * Los canjes y las expiraciones quedan fuera a proposito.
 *
 * Si el tope diario contara el saldo neto, un cliente podria gastar los puntos del
 * dia, volver a comprar y recuperar el margen, y repetirlo tantas veces como
 * quisiera hasta vaciar el programa de puntos. El tope existe para limitar cuanta
 * distancia puede acumular un cliente en un dia, no cuanto saldo puede tener: si
 * decide gastarselo, ese es su derecho, pero no le libera la cuota de nuevo.
 *
 * Los asientos `reverse` si se cuentan, y con signo negativo, porque anular una
 * compra tiene que devolver la cuota que esa compra consumio. Si no, un comercio
 * podria anular repetidamente y acumular mas rapido de lo que el tope permite.
 */
final class LoyaltyRules
{
    public function __construct(private readonly PointCalculator $calculator) {}

    /**
     * Calcula el otorgamiento para una operacion de compra o visita.
     *
     * @param  string  $currency  moneda de la operacion tal como la envio el cajero.
     * @param  bool  $isVisit  la operacion cuenta como visita del cliente.
     */
    public function awardFor(
        Customer $customer,
        int $amountMinor,
        string $currency,
        bool $isVisit = false,
    ): PointsAward {
        $config = $this->currentConfig();

        /*
         * Un tenant sin configuracion no es un error: es un comercio que todavia no
         * ha definido su programa de puntos, y debe poder vender con normalidad. Se
         * devuelve un otorgamiento denegado en lugar de lanzar, para que el POS
         * siga operando y solo los puntos queden en cero.
         */
        if ($config === null) {
            return PointsAward::denied('no_loyalty_configured');
        }

        /*
         * La vigencia se comprueba ANTES de la moneda. Un programa apagado no debe
         * impedir una venta porque el cajero mando otra moneda: no hay puntos en
         * juego, asi que no hay nada que proteger. La comprobacion de moneda solo
         * protege el caso que importa, que es un programa VIVO al que se le estan
         * acreditando puntos a la tasa equivocada.
         */
        if (! $config->isEffectiveAt(now())) {
            return PointsAward::denied('rule_not_effective');
        }

        $this->calculator->assertCurrencyMatches($config, $currency);

        return $this->calculator->calculate(
            $config,
            $amountMinor,
            alreadyEarnedToday: $this->earnedTodayFor($customer),
            isVisit: $isVisit,
        );
    }

    /**
     * Configuracion del tenant actual.
     *
     * `loyalty_configs.tenant_id` es UNIQUE, asi que hay como maximo una fila por
     * tenant y no hace falta elegir entre varias ventanas de vigencia que se
     * solapen. Es publica porque el canje necesita consultar `allow_negative_balance`,
     * y duplicar esta consulta en otro servicio arriesga que las dos rutas resuelvan
     * la config de forma distinta.
     */
    public function currentConfig(): ?LoyaltyConfig
    {
        return LoyaltyConfig::query()->first();
    }

    /**
     * Puntos acumulados por el cliente en su dia local, con signo.
     *
     * El dia se calcula en la zona horaria del cliente y no en la del servidor
     * porque "hoy" para el cliente son las 23 horas que el servidor todavia no ha
     * visto. Un cliente en UTC-6, a las 20:00 hora local ya es el dia siguiente en
     * UTC, y con un corte en UTC perderia las compras de la tarde y las contaria
     * dos veces al dia siguiente. Con el corte en su zona, el dia empieza y acaba
     * cuando el cliente dice.
     */
    private function earnedTodayFor(Customer $customer): int
    {
        [$from, $to] = $this->localDayWindow($customer);

        /*
         * `whereIn` explicito, y no `where('entry_type', $tipos)`.
         *
         * Laravel NO convierte un array en el segundo argumento de `where()` en un
         * `whereIn`: con dos argumentos, `prepareValueAndOperator()` asume que el
         * operador es `=` y compila `entry_type = 'earn'`, quedandose solo con el
         * PRIMER valor de la lista y descartando `adjust` y `reverse` en silencio.
         * El sintoma era que los ajustes manuales y las anulaciones no contaban para
         * el tope diario, sin ningun error que lo delatara.
         */
        return (int) PointLedger::query()
            ->where('customer_id', $customer->getKey())
            ->whereIn('entry_type', $this->accrualEntryTypes())
            ->where('recorded_at', '>=', $from)
            ->where('recorded_at', '<', $to)
            ->sum('points');
    }

    /**
     * Asientos que consumen o liberan la cuota diaria.
     *
     * Los canjes (`redeem`) y las expiraciones (`expire`) se excluyen
     * deliberadamente; ver la nota de clase sobre por que.
     *
     * @return list<LedgerEntryType>
     */
    private function accrualEntryTypes(): array
    {
        return [
            LedgerEntryType::Earn,
            LedgerEntryType::Adjust,
            // Un `reverse` es un asiento negativo que anula una acreditacion
            // anterior, asi que resta cuota consumida.
            LedgerEntryType::Reverse,
        ];
    }

    /**
     * Ventana [desde, hasta) del dia local del cliente, en instantes UTC.
     *
     * El limite superior es EXCLUSIVO. Usar `<=` en el cierre del dia contaria
     * dos veces la primera compra del dia siguiente, que cae exactamente en la
     * frontera: es un instante unico, pero basta para que un cliente que compra
     * justo al cambio de dia se lleve el doble del tope diario.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function localDayWindow(Customer $customer): array
    {
        $timezone = $this->timezoneFor($customer);

        /*
         * Inmutable a proposito, y no por estilo.
         *
         * `Date::now()` devuelve un `Carbon` MUTABLE: `startOfDay()` no devuelve una
         * copia, modifica el objeto y lo devuelve. Con dos llamadas encadenadas sobre
         * la misma variable, el "inicio" se convertia en el dia siguiente y la
         * ventana quedaba como [X, X), es decir VACIA. El sintoma era silencioso: el
         * tope diario simplemente no se aplicaba nunca, sin un solo
         * error. Trabajar con `CarbonImmutable` hace que cada operacion devuelva una
         * instancia nueva y los dos extremos no puedan aliasear.
         */
        $localNow = Date::now($timezone)->toImmutable();

        $start = $localNow->startOfDay();

        // `addDay` es consciente del calendario, no de 24 horas fijas: en un cambio
        // de horario de verano el dia siguiente empieza 23 o 25 horas despues, y un
        // `+24h` cortero se saltaria una hora o repetiria parte del dia.
        $end = $start->addDay();

        return [$start->utc(), $end->utc()];
    }

    /**
     * Zona horaria efectiva del cliente.
     *
     * Se valida contra la lista de identificadores de PHP porque el valor viene de
     * datos de la base y un identificador corrupto haria que `Date::now()` lanzara
     * una excepcion en mitad de una venta, dejando al cajero sin poder cobrar. Caer
     * a UTC es la salida segura: es la zona canonica de almacenamiento, asi que
     * puede contar dos veces una compra, pero nunca romper el cobro ni saltarse un
     * tope.
     */
    private function timezoneFor(Customer $customer): string
    {
        $timezone = $customer->timezone;

        if (! is_string($timezone) || $timezone === '') {
            return 'UTC';
        }

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            return 'UTC';
        }

        return $timezone;
    }
}

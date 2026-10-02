<?php

declare(strict_types=1);

namespace App\Services\Loyalty;

use App\Enums\LedgerEntryType;
use App\Enums\RedemptionCodeStatus;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Customer;
use App\Models\PointLedger;
use App\Models\RedemptionCode;
use App\Models\Reward;
use App\Models\Transaction;
use App\Services\Loyalty\Exceptions\CustomerNotEligible;
use App\Services\Loyalty\Exceptions\InsufficientPoints;
use App\Services\Loyalty\Exceptions\PointsBalanceMismatch;
use App\Services\Loyalty\Exceptions\RewardUnavailable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Escribe acumulaciones y canjes sobre el ledger.
 *
 * --- El orden de las operaciones no es arbitrario ---
 *
 * 1. Cliente con `lockForUpdate`.
 * 2. Calcular el otorgamiento con la config vigente.
 * 3. Insertar la cabecera `transactions`.
 * 4. Insertar el asiento en `point_ledger`.
 * 5. Actualizar el saldo materializado del cliente.
 *
 * El bloqueo va PRIMERO y en su propia transaccion porque el saldo es un contador
 * leido-modificado-escrito: dos cajeros cobrando a la vez en el mismo cliente
 * leerian el mismo saldo y el segundo escribiria encima del primero, perdiendo
 * puntos de forma silenciosa. El `lockForUpdate` convierte la operacion en una
 * fila a la vez, y todo lo que sigue ve un saldo coherente.
 *
 * El calculo va DESPUES del bloqueo a proposito. Si fuera antes, dos transacciones
 * concurrentes calcularian el tope diario sobre el mismo "ya ganado hoy" y ambas
 * acreditarian puntos que solo caben una vez.
 *
 * --- Idempotencia en dos niveles ---
 *
 * El chequeo previo evita el trabajo duplicado en el caso normal (un reintento del
 * POS). El indice unico `transactions.idempotency_key` es la garantia real: cubre
 * la carrera en la que dos peticiones pasan el chequeo a la vez y ambas intentan
 * escribir. Si se pierde esa carrera, se recupera la transaccionoriginal en lugar
 * de fallar, porque el cliente ya fue cobrado y un error aqui se traduciria en un
 * cobro sin puntos.
 */
final class PointsService
{
    public function __construct(private readonly LoyaltyRules $rules) {}

    /**
     * Acumula puntos por una compra o visita.
     *
     * @param  string  $idempotencyKey  clave que el POS reutiliza al reintentar.
     * @param  string|null  $externalRef  identificador del ticket en el sistema del
     *                                    comercio. Cuando viene, actua como
     *                                    referencia del asiento y evita que dos
     *                                    tickets distintos acrediten el mismo hecho.
     */
    public function earn(
        int $customerId,
        int $amountMinor,
        string $currency,
        string $idempotencyKey,
        ?int $operatorId = null,
        TransactionSource $source = TransactionSource::PosScanClient,
        ?string $externalRef = null,
        bool $isVisit = false,
        ?int $qrTokenId = null,
        ?string $notes = null,
    ): Transaction {
        $existing = $this->findByIdempotencyKey($idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($customerId, $amountMinor, $currency, $idempotencyKey, $operatorId, $source, $externalRef, $isVisit, $qrTokenId, $notes): Transaction {
                $customer = $this->lockCustomer($customerId);

                if (! $customer->canEarn()) {
                    throw CustomerNotEligible::cannotEarn($customer);
                }

                $award = $this->rules->awardFor($customer, $amountMinor, $currency, $isVisit);

                $points = $award->points;

                $balanceAfter = $customer->applyPointsDelta($points);

                $transaction = new Transaction([
                    'customer_id' => $customer->getKey(),
                    'operator_id' => $operatorId,
                    'qr_token_id' => $qrTokenId,
                    'type' => TransactionType::Earn,
                    'source' => $source,
                    'amount_spent_minor' => $amountMinor,
                    'currency' => $currency,
                    'external_ref' => $externalRef,
                    'idempotency_key' => $idempotencyKey,
                    'notes' => $notes,
                    'metadata' => $this->awardMetadata($award),
                ]);

                $occurredAt = now();

                $transaction->postPointsImpact($points, $balanceAfter, $occurredAt);
                $transaction->save();

                /*
                 * Cabecera con delta 0 y SIN asiento.
                 *
                 * El ledger tiene `CHECK (points <> 0)`, asi que una compra que no
                 * califica no puede generar un asiento de cero. La cabecera se
                 * escribe igual: es la evidencia de que el cajero escaneo y de que
                 * no hubo puntos, con el motivo en `metadata`. Sin ella, un cliente
                 * al que no se le acredito puntos no tiene nada que mostrar en
                 * soporte.
                 */
                if ($points !== 0) {
                    PointLedger::query()->create([
                        'customer_id' => $customer->getKey(),
                        'transaction_id' => $transaction->getKey(),
                        'entry_type' => LedgerEntryType::Earn,
                        'points' => $points,
                        'balance_after' => $balanceAfter,
                        'ref_type' => $this->refTypeFor($externalRef),
                        'ref_id' => $this->refIdFor($externalRef, $transaction),
                        'reason' => $this->reasonFor($award),
                        'recorded_at' => $occurredAt,
                    ]);
                }

                $customer->save();

                return $transaction;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            /*
             * Perdimos la carrera contra otra peticion con la misma clave: esa otra
             * gano y ya escribio la transaccion. Se devuelve la suya en lugar de
             * propagar el error, porque el cliente ya esta cobrado y devolver un
             * fallo dejaria al cajero creyendo que los puntos no se registraron.
             */
            $replayed = $this->findByIdempotencyKey($idempotencyKey);

            if ($replayed === null) {
                throw $exception;
            }

            return $replayed;
        }
    }

    /**
     * Canjea puntos por un premio.
     *
     * El coste NO es un parametro: sale de `reward.points_required`.
     *
     * Aceptarlo desde el controlador permitiria que la peticion dijera "este premio
     * cuesta 1 punto" y el canje saliera gratis. El precio lo fija el catalogo del
     * tenant y el cliente no lo negocia.
     */
    public function redeem(
        int $customerId,
        Reward $reward,
        string $idempotencyKey,
        ?int $operatorId = null,
        TransactionSource $source = TransactionSource::PosScanClient,
        ?string $externalRef = null,
        ?string $notes = null,
    ): Transaction {
        $cost = (int) $reward->points_required;

        if ($cost <= 0) {
            throw PointsBalanceMismatch::invalidCost($cost);
        }

        $existing = $this->findByIdempotencyKey($idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($customerId, $reward, $cost, $idempotencyKey, $operatorId, $source, $externalRef, $notes): Transaction {
                $customer = $this->lockCustomer($customerId);

                if (! $customer->canRedeem()) {
                    throw CustomerNotEligible::cannotRedeem($customer);
                }

                /*
                 * Disponibilidad del premio antes que saldo del cliente.
                 *
                 * El orden importa. Si faltaran puntos y el premio estuviera
                 * agotado, responder "te faltan 30 puntos" seria y el cajero
                 * haria que el cliente los junte para un premio que ya no existe.
                 * No hay nada que transaccionar sin inventario, asi que se descarta
                 * primero lo que no esta en juego.
                 *
                 * El bloqueo de fila del premio es obligatorio, no opcional: sin el,
                 * dos cajeros que canjean el ultimo cupcake leen ambos
                 * `inventory = 1`, los dos pasan el control y el inventario queda en
                 * -1. El UPDATE final lleva su propio `where inventory > 0` como
                 * red de seguridad, pero la fila debe estar bloqueada para que esa
                 * segunda comprobacion sea fiable.
                 */
                $lockedReward = $this->lockReward($reward);
                $this->assertRewardRedeemable($lockedReward, $customer);

                $config = $this->rules->currentConfig();

                /*
                 * El saldo negativo solo se permite si el programa lo autoriza.
                 *
                 * Un canje por encima del saldo genera un saldo negativo, y el
                 * clientepodria seguir acumulando sobre esa deuda sin que el
                 * comercio lo note. Salvo que el tenant lo haya permitido
                 * explicitamente, es mejor rechazar el canje: el cliente ya gastara
                 * los puntos en otra parte.
                 */
                $allowNegative = $config?->allow_negative_balance ?? false;

                if (! $allowNegative && $customer->current_points < $cost) {
                    throw InsufficientPoints::make($customer->current_points, $cost);
                }

                // Negativo: el canje ES la resta, y el asiento lo refleja con signo.
                $delta = -$cost;

                $balanceAfter = $customer->applyPointsDelta($delta);

                $transaction = new Transaction([
                    'customer_id' => $customer->getKey(),
                    'operator_id' => $operatorId,
                    'reward_id' => $reward->getKey(),
                    'type' => TransactionType::Redeem,
                    'source' => $source,
                    'currency' => $config?->currency ?? 'USD',
                    'external_ref' => $externalRef,
                    'idempotency_key' => $idempotencyKey,
                    'notes' => $notes,
                    'metadata' => [
                        'reward_id' => $reward->getKey(),
                        'reward_title' => $reward->title,
                        'cost_points' => $cost,
                    ],
                ]);

                $occurredAt = now();

                $transaction->postPointsImpact($delta, $balanceAfter, $occurredAt);
                $transaction->save();

                PointLedger::query()->create([
                    'customer_id' => $customer->getKey(),
                    'transaction_id' => $transaction->getKey(),
                    'entry_type' => LedgerEntryType::Redeem,
                    'points' => $delta,
                    'balance_after' => $balanceAfter,
                    'ref_type' => $this->refTypeFor($externalRef),
                    'ref_id' => $this->refIdFor($externalRef, $transaction),
                    'reason' => "Canje de {$reward->title}",
                    'recorded_at' => $occurredAt,
                ]);

                $customer->save();

                /*
                 * El inventario se consume DESPUES de que el canje este contabilizado
                 * y solo una vez. Si este UPDATE fallara, la transaccion se revierte
                 * entera y no queda un premio entregado sin stock descontado ni un
                 * descuento entregado sin cobrar los puntos.
                 */
                $this->consumeRewardStock($lockedReward);

                $this->issueRedemptionCode($lockedReward, $customer, $transaction);

                return $transaction;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $replayed = $this->findByIdempotencyKey($idempotencyKey);

            if ($replayed === null) {
                throw $exception;
            }

            return $replayed;
        }
    }

    /**
     * Cliente con bloqueo de fila.
     *
     * `firstOrFail` y no un `find` tolerante: si el cliente no existe o es de otro
     * tenant, RLS ya lo oculta y el fallo debe ser claro, no un `null` que se
     * convierta mas adelante en un error de saldo inexplicable.
     */
    private function lockCustomer(int $customerId): Customer
    {
        return Customer::query()
            ->whereKey($customerId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Premio con bloqueo de fila.
     *
     * Se relee del servidor en lugar de fiarse del modelo que trae el controlador:
     * ese modelo pudo cargarse hace segundos, y para inventario y limites de
     * canje esos segundos son la diferencia entre vender y sobrevender.
     *
     * El orden de bloqueo (cliente, luego premio) es el mismo en todos los canjes
     * para que dos cajeros que canjean premios distintos no se bloqueen en cruz.
     */
    private function lockReward(Reward $reward): Reward
    {
        return Reward::query()
            ->whereKey($reward->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Comprueba que el premio se puede entregar, y a este cliente.
     *
     * Los tres limites son independientes y `null` significa "sin limite" en los
     * tres: `inventory` ilimitado, limite por cliente ausente, tope global ausente.
     * Un catalogo vacio de recargo es legitimo y es el caso mas comun, asi que se
     * trata `null` como permiso y no como dato faltante.
     */
    private function assertRewardRedeemable(Reward $reward, Customer $customer): void
    {
        if (! $reward->is_active) {
            throw RewardUnavailable::inactive($reward->getKey(), $reward->title);
        }

        /*
         * Ventana de vigencia del catalogo.
         *
         * `valid_from` y `valid_until` son columnas sin CHECK, asi que nada impide
         * que un premio quede `is_active = true` con la ventana ya cerrada. Sin
         * esta comprobacion, una promocion de navidad seguiria vendiendose el 6 de
         * enero porque nadie fue a apagarla a mano.
         */
        $now = now();

        if ($reward->valid_from !== null && $reward->valid_from->isFuture()) {
            throw RewardUnavailable::notYetValid(
                $reward->getKey(),
                $reward->title,
                $reward->valid_from->toDateString(),
            );
        }

        if ($reward->valid_until !== null && $reward->valid_until->isPast()) {
            throw RewardUnavailable::expired(
                $reward->getKey(),
                $reward->title,
                $reward->valid_until->toDateString(),
            );
        }

        if ($reward->inventory !== null && $reward->inventory <= 0) {
            throw RewardUnavailable::outOfStock($reward->getKey(), $reward->title);
        }

        if ($reward->max_redemptions_total !== null
            && $reward->redemptions_count >= $reward->max_redemptions_total) {
            throw RewardUnavailable::totalLimitReached(
                $reward->getKey(),
                $reward->title,
                $reward->max_redemptions_total,
            );
        }

        $perCustomer = $reward->max_redemptions_per_customer;

        if ($perCustomer === null) {
            return;
        }

        /*
         * Se cuentan los canjes contabilizados del cliente, no los codigos emitidos.
         *
         * El codigo puede caducar o anularse sin que el canje se deshaga: si el
         * cliente canjea un cafe gratis que luego no llega a usar, sigue siendo un
         * canje consumio su cupo. La transaccion es el registro contable, y es la
         * que sostiene el invariante del ledger.
         */
        $alreadyRedeemed = Transaction::query()
            ->where('customer_id', $customer->getKey())
            ->where('reward_id', $reward->getKey())
            ->where('type', TransactionType::Redeem->value)
            ->where('status', TransactionStatus::Posted->value)
            ->count();

        if ($alreadyRedeemed >= $perCustomer) {
            throw RewardUnavailable::customerLimitReached(
                $reward->getKey(),
                $reward->title,
                $perCustomer,
                $alreadyRedeemed,
            );
        }
    }

    /**
     * Descuenta stock y suma el contador de canjes del premio.
     *
     * El `where('inventory', '>', 0)` del UPDATE no es redundante con el bloqueo
     * de fila anterior: es la garantia de que la columna jamas puede quedar en
     * negativo, y por tanto de que el CHECK de integridad nunca se viola ni por
     * una condicion de carrera ni por una reentrada futura que olvide el bloqueo.
     * Si el UPDATE no tocara ninguna fila, otro canje se llevo la ultima unidad y
     * se aborta la transaccion completa.
     */
    private function consumeRewardStock(Reward $reward): void
    {
        $updated = Reward::query()
            ->whereKey($reward->getKey())
            ->increment('redemptions_count');

        if ($reward->inventory === null) {
            return;
        }

        $decremented = Reward::query()
            ->whereKey($reward->getKey())
            ->where('inventory', '>', 0)
            ->decrement('inventory');

        if ($decremented === 0) {
            // Solo se llega aqui si otra transaccion consumio la ultima unidad
            // entre el bloqueo y este UPDATE. Se aborta todo: nada de canjes a
            // medias ni premios entregados dos veces.
            throw RewardUnavailable::outOfStock($reward->getKey(), $reward->title);
        }

        if ($updated === 0) {
            throw RewardUnavailable::inactive($reward->getKey(), $reward->title);
        }
    }

    /**
     * Emite el codigo canjeable que respalda este canje.
     *
     * Va dentro de la misma transaccion que el cobro de los puntos y el descuento
     * de stock, de modo que el cliente nunca queda con puntos pagados sin codigo
     * que respaldo. `transaction_id` es NOT NULL en el esquema, asi que la cabecera
     * debe existir antes, y por eso se emite al final.
     */
    private function issueRedemptionCode(
        Reward $reward,
        Customer $customer,
        Transaction $transaction,
    ): RedemptionCode {
        $issued = app(RedemptionCodeIssuer::class)->issue($reward);

        return RedemptionCode::query()->create([
            'customer_id' => $customer->getKey(),
            'reward_id' => $reward->getKey(),
            'transaction_id' => $transaction->getKey(),
            'code' => $issued['code'],
            'code_hash' => $issued['code_hash'],
            'status' => RedemptionCodeStatus::Active->value,
            'value_minor' => $reward->value_minor,
            'value_percent' => $reward->value_percent,
            'currency' => $transaction->currency,
            'expires_at' => $issued['expires_at'],
            'metadata' => [
                'reward_code' => $reward->code,
                'reward_title' => $reward->title,
                'cost_points' => (int) $reward->points_required,
            ],
        ]);
    }

    private function findByIdempotencyKey(string $idempotencyKey): ?Transaction
    {
        return Transaction::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * Tipo de referencia del asiento.
     *
     * Con ticket del comercio, la referencia es el PROPIO TICKET y no la
     * transaccion: eso es lo que hace util el indice unico
     * `point_ledger_ref_unique`, porque impide que dos cabeceras distintas
     * acrediten el mismo ticket. Referenciar el id de la transaccion seria
     * trivialmente unico y la garantia no valdria nada.
     */
    private function refTypeFor(?string $externalRef): string
    {
        return $externalRef === null ? 'transaction' : 'external_ref';
    }

    private function refIdFor(?string $externalRef, Transaction $transaction): string
    {
        return $externalRef ?? (string) $transaction->getKey();
    }

    private function reasonFor(PointsAward $award): ?string
    {
        if ($award->wasCapped()) {
            return "Acumulacion recortada por {$award->cappedBy}: "
                ."en teoria eran {$award->rawPoints} y se acreditan {$award->points}";
        }

        return null;
    }

    /**
     * Traza del calculo en la cabecera.
     *
     * Se guarda por que se acreditaron estos puntos y no otros. Sin esto, un
     * cliente que reclame "me debian mas" deja al soporte sin forma de responder,
     * porque la regla que rigio el calculo pudo cambiar desde entonces.
     *
     * @return array<string, mixed>
     */
    private function awardMetadata(PointsAward $award): array
    {
        return array_filter([
            'awarded_points' => $award->points,
            'theoretical_points' => $award->rawPoints,
            'capped_by' => $award->cappedBy,
            'not_earned_reason' => $award->notEarnedReason,
        ], static fn (mixed $value): bool => $value !== null);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Database\Factories\TransactionFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Cabecera de negocio de un movimiento de puntos.
 *
 * `points_delta` y `points_balance_after` son de solo lectura desde el modelo:
 * ver la lista `$guarded`. El unico camino para escribirlos es el ledger.
 */
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use BelongsToTenant;

    use HasFactory;

    protected $fillable = [
        'customer_id',
        'operator_id',
        'qr_token_id',
        'reward_id',
        'reverses_transaction_id',
        'type',
        'status',
        'source',
        'amount_spent_minor',
        'currency',
        'external_ref',
        'idempotency_key',
        'notes',
        'occurred_at',
        'metadata',
    ];

    /**
     * Campos que solo el ledger puede escribir. Incluir cualquiera de estos en un
     * request allowlisting seria un agujero directo al saldo del cliente.
     */
    protected $guarded = [
        'points_delta',
        'points_balance_after',
    ];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'source' => TransactionSource::class,
            'amount_spent_minor' => 'integer',
            'points_delta' => 'integer',
            'points_balance_after' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Asienta el impacto en puntos de una transaccion que ya esta contabilizada.
     *
     * `points_delta` y `points_balance_after` estan en `$guarded`, asi que
     * ningun `create()` ni `update()` con datos de request puede tocarlos: un
     * allowlisting ahi seria un agujero directo al saldo del cliente. Este metodo
     * es el UNICO camino para escribirlos.
     *
     * Escribe de una vez los cuatro campos que el CHECK
     * `transactions_posted_consistency` relaciona entre si (status, delta, saldo
     * y fecha). Agruparlos no es cosmetico: si se escribieran por separado, un
     * fallo a mitad de camino dejaria una fila con status `posted` y sin saldo, que
     * es exactamente el registro que aparenta haber impactado cuando el ledger
     * realmnte no lo hizo. Es mas barato que la base lo rechace.
     *
     * Solo debe llamarse el servicio de ledger, dentro de la misma transaccion
     * que escribe el asiento correspondiente en `point_ledger`.
     */
    public function postPointsImpact(int $pointsDelta, int $balanceAfter, DateTimeInterface $occurredAt): void
    {
        $this->forceFill([
            'points_delta' => $pointsDelta,
            'points_balance_after' => $balanceAfter,
            'occurred_at' => $occurredAt,
            'status' => TransactionStatus::Posted,
        ]);
    }

    /**
     * Marca la transaccion como NO contabilizada.
     *
     * El CHECK exige que `points_balance_after` sea NULL cuando el estado no es
     * `posted`, asi que se limpia explicitamente. Sin esto, anular un cobro que
     * llego a escribirse con saldo seria imposible: el CHECK rechazaria el
     * UPDATE en cuanto se intentara dejar fuera el impacto.
     *
     * `points_delta` tambien se pone a cero, pero por otro motivo: la columna es
     * NOT NULL y no admite null. Cero es el valor correcto en este punto, porque
     * una transaccion pendiente no ha impactado nada todavia. La asimetria es
     * deliberada y refleja lo que significan las dos columnas: el delta es un
     * hecho ("movio estos puntos") y vale cero mientras no haya movimiento; el
     * saldo es un estado y no existe hasta que la operacion se contabiliza.
     *
     * @param  TransactionStatus  $status  `voided` o `failed`. No se acepta
     *                                     `posted`: para contabilizar hay que
     *                                     llamar a `postPointsImpact()`, que es
     *                                     el unico camino que fija el saldo.
     */
    public function markUnposted(TransactionStatus $status, ?string $notes = null): void
    {
        if ($status === TransactionStatus::Posted) {
            throw new LogicException(
                'Para dejar una transaccion en estado posted hay que usar postPointsImpact(), '
                .'que exige el saldo resultante. markUnposted() solo sirve para voided o failed.'
            );
        }

        $this->forceFill([
            'status' => $status,
            'points_delta' => 0,
            'points_balance_after' => null,
        ]);

        if ($notes !== null) {
            $this->notes = $notes;
        }
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }

    public function qrToken(): BelongsTo
    {
        return $this->belongsTo(QrToken::class);
    }

    public function ledgerEntry(): HasOne
    {
        return $this->hasOne(PointLedger::class);
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_transaction_id');
    }

    /**
     * @param  Builder<Transaction>  $query
     */
    public function scopePosted(Builder $query): void
    {
        $query->where('status', TransactionStatus::Posted->value);
    }

    /**
     * @param  Builder<Transaction>  $query
     */
    public function scopeEarned(Builder $query): void
    {
        $query->where('type', TransactionType::Earn->value);
    }

    /**
     * @param  Builder<Transaction>  $query
     */
    public function scopeBetween(Builder $query, DateTimeInterface $from, DateTimeInterface $to): void
    {
        $query->whereBetween('occurred_at', [$from, $to]);
    }
}

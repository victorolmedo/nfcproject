<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\LedgerEntryType;
use Database\Factories\PointLedgerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asiento del libro mayor de puntos. Append-only.
 *
 * El modelo no expone `points` ni `balance_after` en `$fillable` a proposito: un
 * asiento se crea, nunca se modifica. Cualquier logica de negocio que necesite
 * tocar un movimiento anterior lo hace emitiendo un asiento contrario, no
 * actualizando este.
 */
class PointLedger extends Model
{
    /** @use HasFactory<PointLedgerFactory> */
    use BelongsToTenant;

    use HasFactory;

    /**
     * Tabla en singular. El termino "ledger" ya esta en singular y el nombre de
     * la tabla es parte del contrato con los indices y las politicas RLS, asi que
     * se declara explicitamente en vez de depender del pluralizador.
     */
    protected $table = 'point_ledger';

    protected $fillable = [
        'customer_id',
        'transaction_id',
        'entry_type',
        'points',
        'balance_after',
        'ref_type',
        'ref_id',
        'reason',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'entry_type' => LedgerEntryType::class,
            'points' => 'integer',
            'balance_after' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function isCredit(): bool
    {
        return $this->points > 0;
    }
}

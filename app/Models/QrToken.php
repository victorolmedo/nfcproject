<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\QrClaimType;
use App\Enums\QrTokenStatus;
use Database\Factories\QrTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Registro servidor del token efimero del QR dinamico (Flujo B).
 *
 * El JWT que viaja en el QR lleva `jti` y `exp`; esta tabla es la autoridad sobre
 * si ese JWT es valido, ya consumido, o revocado. El JWT solo prueba que fue
 * emitido por nosotros: esta tabla prueba que se puede usar UNA vez.
 */
class QrToken extends Model
{
    /** @use HasFactory<QrTokenFactory> */
    use BelongsToTenant;

    use HasFactory;

    protected $fillable = [
        'jti',
        'issued_by_user_id',
        'customer_id',
        'claim_type',
        'points_awarded',
        'amount_minor',
        'currency',
        'external_ref',
        'metadata',
        'status',
        'issued_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'claim_type' => QrClaimType::class,
            'status' => QrTokenStatus::class,
            'points_awarded' => 'integer',
            'amount_minor' => 'integer',
            'metadata' => 'array',
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'last_scanned_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function preassignedCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function consumer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'consumed_by_customer_id');
    }

    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Un token solo es canjeable si esta en Issued y no vencio.
     *
     * El metodo es informativo. La garantia real la da el UPDATE condicional de
     * QrTokenService::consume(), que re-chquea ambas condiciones en la base.
     */
    public function isClaimable(): bool
    {
        return $this->status === QrTokenStatus::Issued && ! $this->isExpired();
    }
}

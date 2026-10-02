<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\StaffRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * Personal del comercio: cajeros y administradores.
 *
 * El cliente final del programa de fidelizacion no es un User: se identifica por
 * su pase. Por eso el login es siempre (tenant_slug, email, password) y nunca
 * solo (email, password).
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant;

    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'phone_e164',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Normaliza el email en un unico sitio.
     *
     * Todo alta y toda busqueda deben pasar por aqui. El indice unico es
     * `(tenant_id, email)`, y PostgreSQL compara texto de forma binaria: "Ana@x.com"
     * y "ana@x.com" son dos filas distintas y dos cuentas separadas con el mismo
     * correo. Normalizar en la escritura y en la lectura evita esa clase de bug,
     * y centralizarlo evita el forgetting tipico de normalizar en un camino y no
     * en el otro.
     */
    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            if ($user->isDirty('email') && is_string($user->email)) {
                $user->email = Str::lower(trim($user->email));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
            'role' => StaffRole::class,
            'is_active' => 'boolean',
            'last_login_at' => 'immutable_datetime',
            'locked_until' => 'immutable_datetime',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'operator_id');
    }

    /**
     * Permisos derivados del rol.
     *
     * Se mapean a abilities de Sanctum para que los middleware `ability:` sean
     * la unica fuente de verdad de autorizacion. Un endpoint nuevo no necesita
     * logica propia: se declara con `middleware('ability:pos.redeem')` y ya.
     */
    public function abilities(): array
    {
        return match ($this->role) {
            StaffRole::Cashier => [
                'pos.earn',
                'pos.redeem',
                'pos.issue-qr',
                'customers.read',
            ],
            StaffRole::Admin => [
                'pos.earn',
                'pos.redeem',
                'pos.issue-qr',
                'customers.read',
                'customers.write',
                'rewards.read',
                'rewards.write',
                'loyalty-config.read',
                'loyalty-config.write',
                'reports.read',
                'staff.read',
                /*
                 * `staff.write` estaba ausente y solo figuraba `staff.read`, lo que
                 * contradecia el propio docblock del enum ("gestiona ... personal")
                 * y dejaba al administrador sin poder dar de alta a un cajero, que
                 * es la primera accion que necesita al montar un comercio.
                 */
                'staff.write',
            ],
            StaffRole::Owner => [
                'pos.earn',
                'pos.redeem',
                'pos.issue-qr',
                'customers.read',
                'customers.write',
                'rewards.read',
                'rewards.write',
                'loyalty-config.read',
                'loyalty-config.write',
                'reports.read',
                'staff.read',
                'staff.write',
                'billing.read',
                'tenant.manage',
            ],
        };
    }

    public function hasStaffRole(StaffRole $required): bool
    {
        return $this->role->covers($required);
    }

    public function isOwner(): bool
    {
        return $this->role === StaffRole::Owner;
    }

    /**
     * Un usuario bloqueado por intentos fallidos no puede autenticarse hasta que
     * venza `locked_until`. Defense en profundidad: el rate limiter de Laravel ya
     * frena el ataque, esto evita el lockout permanente de una cuenta legitima.
     */
    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}

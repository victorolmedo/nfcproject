<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\LoyaltyRuleType;
use App\Enums\PointsRounding;
use App\Enums\RewardType;
use App\Enums\StaffRole;
use App\Enums\TenantStatus;
use App\Models\LoyaltyConfig;
use App\Models\Reward;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Crea un comercio de demostracion completo: reglas, personal y catalogo de
 * premios. Pensado para desarrollo y para levantar el entorno de staging con
 * datos creibles sin tocar la base a mano.
 *
 * Es idempotente: se puede correr varias veces sin duplicar nada.
 */
class DemoCafeSeeder extends Seeder
{
    public function run(): void
    {
        $rootDomain = config('loyaltypass.root_domain', 'localhost:8000');

        $attributes = [
            'name' => 'Cafe Aurora',
            'legal_name' => 'Cafe Aurora S.A. de C.V.',
            'tax_id' => 'AUR010101XXX',
            'logo_url' => 'https://placehold.co/240x240/1C1917/D97706?text=A',
            'primary_color' => '#1C1917',
            'secondary_color' => '#D97706',
            'timezone' => 'America/Mexico_City',
            'currency' => 'MXN',
            'status' => TenantStatus::Active,
            'activated_at' => now()->subMonths(2),
            'settings' => [
                'welcome_message' => 'Bienvenido a Cafe Aurora. Acumula 1 punto por cada $10 gastado.',
                'support_email' => 'hola@cafeaurora.mx',
            ],
        ];

        /*
         * El alta del tenant se puede hacer sin contexto: la politica
         * `tenants_insert` lo permite explicitamente porque en el momento del
         * alta todavia no existe un tenant al que pertenecer.
         *
         * El UPDATE de un tenant ya existente si exige contexto (politica
         * `tenants_update`: `id = app_current_tenant_id()`), porque modificar la
         * marca o la moneda de un comercio es una operacion que solo su propia
         * sesion autenticada debe poder hacer.
         *
         * De ahi la division: resolver o crear por slug, y luego todo lo demas
         * dentro de TenantContext::run().
         */
        $tenant = Tenant::query()->firstWhere('slug', 'cafe-aurora');

        if ($tenant === null) {
            $tenant = Tenant::query()->create($attributes + ['slug' => 'cafe-aurora']);
        }

        TenantContext::run($tenant, function () use ($tenant, $attributes, $rootDomain): void {
            $tenant->fill($attributes)->save();

            TenantDomain::query()->updateOrCreate(
                ['host' => "cafe-aurora.{$rootDomain}"],
                ['tenant_id' => $tenant->id, 'is_primary' => true, 'verified_at' => now()],
            );

            $this->seedStaff($tenant);
            $this->seedLoyaltyConfig($tenant);
            $this->seedRewards($tenant);
        });
    }

    /**
     * @param  array<string, array{0: string, 1: StaffRole}>  $staff
     */
    private function seedStaff(Tenant $tenant, array $staff = []): void
    {
        $staff = $staff ?: [
            ['dueno@cafeaurora.mx', StaffRole::Owner, 'Ana Aurora'],
            ['admin@cafeaurora.mx', StaffRole::Admin, 'Luis Barra'],
            ['caja1@cafeaurora.mx', StaffRole::Cashier, 'Marco Diaz'],
            ['caja2@cafeaurora.mx', StaffRole::Cashier, 'Sofia Rios'],
        ];

        foreach ($staff as [$email, $role, $name]) {
            User::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'email' => $email],
                [
                    'name' => $name,
                    'role' => $role,
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'is_active' => true,
                ],
            );
        }
    }

    private function seedLoyaltyConfig(Tenant $tenant): void
    {
        LoyaltyConfig::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'name' => 'Cafe Aurora Clasico',
                'rule_type' => LoyaltyRuleType::Amount,

                // 1 punto por cada 10 pesos. 1000 centavos => 0.001 puntos/centavo.
                'points_per_minor_unit' => 0.001,
                'points_per_visit' => 0,

                'min_purchase_minor' => 0,
                'currency' => 'MXN',

                // Topes: null = sin tope. Un catalogo de baja friccion es mejor
                // para captar, y el negocio puede toparlo despues si el costo lo
                // exige. La columna queda lista para cuando haga falta.
                'max_points_per_transaction' => null,
                'max_points_per_day' => null,

                'rounding' => PointsRounding::Down,
                'allow_negative_balance' => false,
                'points_expiry_months' => null,
                'welcome_points' => 5,
                'is_active' => true,
            ],
        );
    }

    private function seedRewards(Tenant $tenant): void
    {
        $rewards = [
            [
                'code' => 'CAFEGRATIS',
                'title' => 'Cafe gratis',
                'description' => 'Elige el cafe que quieras, sin limite de tamaño.',
                'reward_type' => RewardType::FreeItem,
                'points_required' => 80,
                'is_featured' => true,
                'sort_order' => 1,
            ],
            [
                'code' => 'CAPTUCHA',
                'title' => 'Medio litro de capuchino',
                'description' => 'Incluye leche de avena sin costo adicional.',
                'reward_type' => RewardType::FreeItem,
                'points_required' => 150,
                'is_featured' => true,
                'sort_order' => 2,
            ],
            [
                'code' => 'DESC20',
                'title' => '20% de descuento',
                'description' => 'Aplicable a cualquier cafe o panaderia, no acumulable.',
                'reward_type' => RewardType::PercentDiscount,
                'points_required' => 200,
                'value_minor' => null,
                'value_percent' => 20,
                'sort_order' => 3,
            ],
            [
                'code' => 'DESC50',
                'title' => '$50 de descuento',
                'description' => 'Descuento fijo sobre el total de tu compra.',
                'reward_type' => RewardType::FixedDiscount,
                'points_required' => 300,
                'value_minor' => 5000,
                'sort_order' => 4,
            ],
            [
                'code' => 'GIFT200',
                'title' => 'Gift card de $200',
                'description' => 'Codigo canjeable en cualquier sucursal de Cafe Aurora.',
                'reward_type' => RewardType::GiftCard,
                'points_required' => 400,
                'value_minor' => 20000,
                'inventory' => 50,
                'max_redemptions_per_customer' => 2,
                'sort_order' => 5,
            ],
        ];

        foreach ($rewards as $reward) {
            Reward::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $reward['code']],
                array_merge([
                    'value_minor' => null,
                    'value_percent' => null,
                    'inventory' => null,
                    'max_redemptions_per_customer' => null,
                    'redemption_valid_days' => 90,
                    'is_active' => true,
                    'is_featured' => false,
                    'sort_order' => 0,
                    'metadata' => [],
                ], $reward),
            );
        }
    }
}

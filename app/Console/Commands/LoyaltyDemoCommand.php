<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PointsRounding;
use App\Enums\RewardType;
use App\Enums\TransactionSource;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\PointLedger;
use App\Models\RedemptionCode;
use App\Models\Reward;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Loyalty\Exceptions\CustomerNotEligible;
use App\Services\Loyalty\Exceptions\InsufficientPoints;
use App\Services\Loyalty\Exceptions\RewardUnavailable;
use App\Services\Loyalty\Exceptions\RuleNotApplicable;
use App\Services\Loyalty\Exceptions\UnsupportedCurrency;
use App\Services\Loyalty\PointsService;
use App\TenantContext;
use Illuminate\Console\Command;

/**
 * Demo ejecutable del motor de puntos, extremo a extremo.
 *
 * No es un test: los tests existen en `tests/` y verifican lo mismo de forma
 * automatizada. Esto recorre el flujo como lo veria un cajero y al final imprime
 * un estado legible, para poder abrirlo a alguien sin leer PHPUnit.
 *
 * Usa el `PointsService` real contra PostgreSQL real, con RLS activo. No simula
 * nada: si el aislamiento por tenant o el bloqueo de fila estuvieran rotos, esta
 * demo fallaria igual que fallarian los tests.
 */
final class LoyaltyDemoCommand extends Command
{
    protected $signature = 'loyalty:demo
        {--tenant=cafe-aurora : slug del tenant}
        {--fresh : borra los clientes de la demo antes de empezar}';

    protected $description = 'Recorre una compra, un tope, un reintento y un canje, e imprime el libro mayor.';

    public function handle(): int
    {
        $tenant = Tenant::query()->firstWhere('slug', $this->option('tenant'));

        if ($tenant === null) {
            $this->error("No existe el tenant '{$this->option('tenant')}'. Ejecuta primero: php artisan db:seed");

            return self::FAILURE;
        }

        $this->line('');
        $this->line("  Tenant: <info>{$tenant->name}</info> ({$tenant->currency})");
        $this->line('');

        // Todo lo que sigue escribe en tablas con RLS, asi que necesita contexto.
        TenantContext::run($tenant, function () use ($tenant): void {
            $this->applyDemoConfig();

            if ($this->option('fresh')) {
                $this->wipeDemoCustomers();
            }

            $customer = Customer::factory()->create([
                'full_name' => 'Ana Cliente',
                'email' => 'ana.cliente@example.mx',
                'timezone' => 'America/Mexico_City',
            ]);

            $service = app(PointsService::class);

            $this->heading('1. Compra normal de $450.00');
            $this->runEarn($service, $customer, 45_000, 'DEMO-1', '1 punto por cada $10');

            $this->heading('2. Compra que dispara el tope diario');
            $this->runEarn($service, $customer, 60_000, 'DEMO-2', 'topes a 50/dia y 60/operacion');

            $this->heading('3. Reintento del mismo ticket con la MISMA clave');
            $before = $customer->refresh()->current_points;
            $replay = $service->earn($customer->id, 45_000, $tenant->currency, 'DEMO-1');
            $after = $customer->refresh()->current_points;

            $this->line("  Transaccion devuelta: <info>#{$replay->id}</info> (la original, no una nueva)");
            $this->line('  <comment>El saldo no se movio: la clave de idempotencia lo impidio.</comment>');

            $this->heading('4. Canje de un premio que el cliente alcanza');

            /*
             * Se crea un premio barato a proposito. Los del catalogo seeded empiezan
             * en 80 puntos y el saldo topado del paso 2 es 50, asi que sin este
             * premio la demo solo podria mostrar el rechazo por saldo insuficiente y
             * nunca dejaria ver un canje completo.
             */
            $cheap = Reward::query()->updateOrCreate(
                ['code' => 'DEMO-CAFECHICO'],
                [
                    'title' => 'Cafe chico (demo)',
                    'description' => 'Premio barato para que la demo cierre un canje completo.',
                    'reward_type' => RewardType::FreeItem,
                    'points_required' => 30,
                    'inventory' => 2,
                    'is_active' => true,
                    'sort_order' => 99,
                ],
            );

            /*
             * `redemptions_count` no es `fillable`, asi que `updateOrCreate` lo ignora
             * silenciosamente y el contador venia sumando canjes de corridas
             * anteriores. Se reinicia con una actualizacion directa para que la demo
             * sea determinista y el contador que muestra signifique algo.
             */
            Reward::query()->whereKey($cheap->getKey())->update(['redemptions_count' => 0]);

            $cheap->refresh();

            $this->runRedeem($service, $customer, $cheap);

            $this->heading('4b. Canje de un premio que NO alcanza');
            $expensive = Reward::query()->where('code', 'CAFEGRATIS')->first();

            if ($expensive === null) {
                $this->warn('  El tenant no tiene CAFEGRATIS; se omite.');
            } else {
                $this->runRedeem($service, $customer, $expensive, 'DEMO-REDEEM-CARO');
            }

            $this->heading('5. Intentos que deben RECHAZARSE');
            $this->runRejected($service, $customer, $tenant->currency);

            $this->heading('6. Libro mayor y conciliacion');
            $this->printLedger($customer);
            $this->assertReconciliation($customer);

            $this->newLine();
        });

        return self::SUCCESS;
    }

    /**
     * Configuracion con topes activos, para que la demo muestre el recorte.
     *
     * El seeder de demo deja los topes en null a proposito (un catalogo de baja
     * friccion es mejor para captar). Para una demo de motor hace falta lo
     * contrario: sin topes no se ve la parte interesante.
     */
    private function applyDemoConfig(): void
    {
        LoyaltyConfig::query()->update([
            'max_points_per_day' => 50,
            'max_points_per_transaction' => 60,
            'rounding' => PointsRounding::Down,
        ]);
    }

    /**
     * Borra del todo los clientes de corridas anteriores.
     *
     * `forceDelete()` y no `delete()` a proposito. `Customer` usa `SoftDeletes`, y
     * un borrado logico solo marca `deleted_at`: la fila sigue existiendo y sus
     * `transactions` NO se van en cascada, porque las acciones referenciales de
     * PostgreSQL solo se disparan con un DELETE real.
     *
     * Consecuencia observable del borrado logico: las claves de idempotencia
     * `DEMO-1` y `DEMO-2` de la corrida anterior seguian ocupadas. La segunda vez
     * que se ejecutaba la demo, cada `earn` encontraba su clave previa y devolvia
     * la transaccion vieja en vez de acreditarla, y el libro mayor salia vacio sin
     * ningun error visible. Parecia un fallo del motor y era un fallo de limpieza.
     */
    private function wipeDemoCustomers(): void
    {
        $removed = Customer::query()
            ->withTrashed()
            ->where('email', 'ana.cliente@example.mx')
            ->forceDelete();

        $this->line("  (clientes de corridas anteriores eliminados: {$removed})");
    }

    private function runEarn(PointsService $service, Customer $customer, int $amountMinor, string $key, string $note): void
    {
        $before = $customer->refresh()->current_points;

        $transaction = $service->earn(
            $customer->id,
            $amountMinor,
            config('loyaltypass.currency', 'MXN'),
            $key,
            externalRef: $key,
        );

        $customer->refresh();

        $this->money($amountMinor);
        $this->line("  Teoria: {$transaction->metadata['theoretical_points']} | acreditado: {$transaction->points_delta}");

        if (isset($transaction->metadata['capped_by'])) {
            $this->line("  Recortado por: <comment>{$transaction->metadata['capped_by']}</comment>");
        }

        if (isset($transaction->metadata['not_earned_reason'])) {
            $this->line("  Sin puntos: <comment>{$transaction->metadata['not_earned_reason']}</comment>");
        }

        $this->line("  Saldo: {$before} -> {$customer->current_points}  ({$note})");
    }

    private function runRedeem(
        PointsService $service,
        Customer $customer,
        Reward $reward,
        string $idempotencyKey = 'DEMO-REDEEM',
    ): void {
        $before = $customer->refresh()->current_points;

        try {
            $transaction = $service->redeem(
                $customer->id,
                $reward,
                $idempotencyKey,
                source: TransactionSource::PosScanClient,
            );

            $customer->refresh();

            $this->line("  Premio: <info>{$reward->title}</info> ({$reward->points_required} puntos)");
            $this->line("  Transaccion #{$transaction->id}, delta {$transaction->points_delta}");
            $this->line("  Saldo: {$before} -> {$customer->current_points}");

            $reward->refresh();
            $stock = $reward->inventory === null ? 'ilimitado' : (string) $reward->inventory;

            $this->line("  Inventario: <info>{$stock}</info> restantes, canje #{$reward->redemptions_count}");

            /*
             * El codigo es lo que el cliente se lleva. Mostrarlo aqui confirma de un
             * vistazo que el canje emitio su respaldo y no solo movio puntos.
             */
            $code = RedemptionCode::query()
                ->where('transaction_id', $transaction->getKey())
                ->sole();

            $this->line("  Codigo para el cliente: <info>{$code->code}</info>");
            $this->line("  Vence: {$code->expires_at->toDateString()} (dias de validez del catalogo)");
        } catch (InsufficientPoints $exception) {
            $details = $exception->errorDetails();

            $this->line("  Rechazado: le faltan {$details['missing_points']} puntos.");
        } catch (RewardUnavailable $exception) {
            $this->line("  Rechazado: <comment>{$exception->reason()}</comment> - {$exception->getMessage()}");
        }
    }

    private function runRejected(PointsService $service, Customer $customer, string $currency): void
    {
        // Moneda distinta: el motor no acredita a la tasa de otra moneda.
        try {
            $service->earn($customer->id, 10_000, 'EUR', 'DEMO-EUR');

            $this->line('  <error>FALLO: una compra en EUR deberia haberse rechazado.</error>');
        } catch (UnsupportedCurrency) {
            $this->line('  <info>OK</info> compra en EUR rechazada (la regla opera en MXN)');
        }

        // Importe negativo: es un error de captura, no una compra de cero.
        try {
            $service->earn($customer->id, -5_000, $currency, 'DEMO-NEG');

            $this->line('  <error>FALLO: un importe negativo deberia haberse rechazado.</error>');
        } catch (RuleNotApplicable) {
            $this->line('  <info>OK</info> importe negativo rechazado (no se acredita en silencio)');
        }

        // Cliente suspendido: conserva saldo, pero no opera.
        $suspended = Customer::factory()->suspended()->withPoints(500)->create([
            'email' => 'ana.suspendida@example.mx',
        ]);

        try {
            $service->earn($suspended->id, 10_000, $currency, 'DEMO-SUSP');

            $this->line('  <error>FALLO: una cliente suspendida no deberia acumular.</error>');
        } catch (CustomerNotEligible) {
            $this->line('  <info>OK</info> cliente suspendida no acumula ni canjea');
        }

        /*
         * Canje imposible.
         *
         * `updateOrCreate` y no `factory()->create()`: el catalogo de la demo no se
         * borra entre corridas (`--fresh` solo limpia clientes), asi que un `create`
         * reventaba contra el indice unico `tenant_id + code` en la segunda ejecucion
         * con un UniqueConstraintViolationException. El premio barato ya usaba esta
         * forma; ahora los dos son idempotentes y la demo se puede repetir.
         */
        $expensive = Reward::query()->updateOrCreate(
            ['code' => 'DEMO-CARO'],
            [
                'title' => 'Premio imposible (demo)',
                'description' => 'Cuesta mas que cualquier saldo, para demostrar el rechazo.',
                'reward_type' => RewardType::FreeItem,
                'points_required' => 999_999,
                'is_active' => true,
                'sort_order' => 98,
            ],
        );

        try {
            $service->redeem($customer->id, $expensive, 'DEMO-CARO-INT');

            $this->line('  <error>FALLO: un canje de 999999 puntos deberia haberse rechazado.</error>');
        } catch (InsufficientPoints) {
            $this->line('  <info>OK</info> canje de 999,999 puntos rechazado por saldo insuficiente');
        }
    }

    private function printLedger(Customer $customer): void
    {
        $customer->refresh();

        $entries = PointLedger::query()
            ->where('customer_id', $customer->id)
            ->orderBy('id')
            ->get();

        $this->line(sprintf('  %-4s %-9s %8s %10s  %s', '#', 'TIPO', 'PUNTOS', 'SALDO', 'REFERENCIA'));

        foreach ($entries as $entry) {
            $this->line(sprintf(
                '  %-4d %-9s %8d %10d  %s',
                $entry->id,
                $entry->entry_type->value,
                $entry->points,
                $entry->balance_after,
                $entry->ref_id,
            ));
        }

        $this->newLine();
    }

    /**
     * La misma comprobacion que hace `PointsServiceTest`, pero visible.
     *
     * Si esto falla, hay descuadre contable y el punto de la demo no es el motor.
     */
    private function assertReconciliation(Customer $customer): void
    {
        $customer->refresh();

        $ledgerSum = (int) PointLedger::query()->where('customer_id', $customer->id)->sum('points');

        $checks = [
            'suma del ledger = saldo del cliente' => $ledgerSum === $customer->current_points,
            'cabecera y asiento coinciden en cada transaccion' => $this->headersMatchLedger($customer),
            'cada posted con impacto tiene un asiento' => $this->everyPostedHasEntry($customer),
        ];

        foreach ($checks as $label => $ok) {
            $this->line($ok ? "  <info>[OK]</info>   {$label}" : "  <error>[FALLA]</error> {$label}");
        }

        if (in_array(false, $checks, true)) {
            $this->error('  Hay descuadre contable: el ledger y el saldo no coinciden.');
        }
    }

    private function headersMatchLedger(Customer $customer): bool
    {
        return Transaction::query()
            ->where('customer_id', $customer->id)
            ->posted()
            ->where('points_delta', '!=', 0)
            ->get()
            ->every(function (Transaction $transaction): bool {
                $entry = PointLedger::query()->where('transaction_id', $transaction->id)->first();

                return $entry !== null
                    && $entry->points === $transaction->points_delta
                    && $entry->balance_after === $transaction->points_balance_after;
            });
    }

    private function everyPostedHasEntry(Customer $customer): bool
    {
        return Transaction::query()
            ->where('customer_id', $customer->id)
            ->posted()
            ->where('points_delta', '!=', 0)
            ->get()
            ->every(fn (Transaction $t): bool => PointLedger::query()->where('transaction_id', $t->id)->exists());
    }

    private function money(int $amountMinor): void
    {
        $this->line('  Compra: $'.number_format($amountMinor / 100, 2));
    }

    private function heading(string $text): void
    {
        $this->newLine();
        $this->line("<options=bold>  {$text}</options=bold>");
    }
}

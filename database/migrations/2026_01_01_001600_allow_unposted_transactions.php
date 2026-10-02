<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permite registrar transacciones que todavia no han impactado el saldo.
 *
 * --- El problema ---
 *
 * La migracion original declaro `points_balance_after` como `NOT NULL`, pero el
 * CHECK `transactions_posted_consistency` exige lo contrario:
 *
 *     (status = 'posted' AND points_balance_after IS NOT NULL AND occurred_at IS NOT NULL)
 *     OR (status <> 'posted' AND points_balance_after IS NULL)
 *
 * Las dos condiciones son incompatibles: una fila no posted necesita el campo a
 * NULL y la columna no lo permite. En la practica, `INSERTAR` una transaccion
 * `pending`, `voided` o `failed` es IMPOSIBLE, porque cualquier valor enviado
 * viola el CHECK y `NULL` viola el NOT NULL. El CHECK que pretendia proteger esa
 * invariante la hacia inalcanzable.
 *
 * --- Por que importa mas de lo que parece ---
 *
 * Sin esta correccion, no hay forma de registrar un cobro que se libro pero cuyo
 * acreditacion fallo, ni una anulacion en curso. El unico camino posible es
 * escribir directamente en estado `posted`, es decir, mentir sobre el resultado
 * para poder insertar. Un ledger que obliga a mentir sobre el estado no es un
 * ledger auditable.
 *
 * No se relaja el CHECK: sigue siendo la garantia de que ninguna transaccion no
 * contabilizada aparenta haber impactado el saldo. Solo se permite que exista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function ($table): void {
            $table->bigInteger('points_balance_after')->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * Volver a NOT NULL exige decidir que hacer con las filas no posted que ya
         * existan. No se puede hacer con un simple `ALTER`: habria que resolverlas
         * antes, y resolverlas significa o borrarlas o inventarles un saldo.
         *
         * Por eso la operacion inversa se rechaza de forma explicita en vez de
         * dejar una migracion que falla a mitad de un rollback de produccion. Quien
         * necesite revertir debe decidir primero que hacer con esas filas.
         */
        $nonPosted = DB::table('transactions')
            ->where('status', '!=', 'posted')
            ->count();

        if ($nonPosted > 0) {
            throw new RuntimeException(
                "No se puede revertir: hay {$nonPosted} transaccion(es) en estado distinto de 'posted'. "
                .'Resuelvelas o eliminalas antes de volver a hacer NOT NULL la columna.'
            );
        }

        Schema::table('transactions', function ($table): void {
            $table->bigInteger('points_balance_after')->nullable(false)->change();
        });
    }
};

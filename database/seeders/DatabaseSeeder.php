<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Punto de entrada del seeder.
 *
 * Solo crea el comercio de demostracion. No se siembran clientes, transacciones
 * ni puntos: esos datos los produce el motor de ledger, y sembrarlos a mano
 * generaria saldos que no reconcilian con el ledger, que es exactamente la clase
 * de inconsistencia que el modelo esta disenado para hacer imposible.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DemoCafeSeeder::class,
        ]);
    }
}

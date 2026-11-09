<?php

/*
 * Punto de entrada de `php artisan db:seed`. Cuándo tocarlo: al añadir un sembrado nuevo.
 *
 * Solo siembra el CATÁLOGO inicial. NO crea usuarios: el esqueleto creaba uno de prueba, pero con
 * el modelo de roles ya no es válido (R18: las cuentas se crean por invitación).
 */

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CatalogSeeder::class);
    }
}

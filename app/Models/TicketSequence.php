<?php

/*
 * Contador del número de ticket, por año. Qué es: reparte `2026-0001`, `2026-0002`…
 * Cuándo tocarlo: nunca debería hacer falta. Es el único sitio que asigna números.
 *
 * Por qué así: se bloquea la fila del año (SELECT … FOR UPDATE) dentro de una transacción, de
 * modo que dos altas simultáneas no pueden recibir el mismo número.
 * Trampa: NO sustituir por `MAX(sequence) + 1`: con concurrencia repite números.
 */

namespace App\Models;

use Illuminate\Support\Facades\DB;

class TicketSequence
{
    /** Devuelve el siguiente número libre del año y lo reserva. */
    public static function next(int $year): int
    {
        return DB::transaction(function () use ($year): int {
            DB::table('ticket_sequences')->insertOrIgnore(['year' => $year, 'last_value' => 0]);

            $actual = DB::table('ticket_sequences')->where('year', $year)->lockForUpdate()->value('last_value');
            $siguiente = $actual + 1;
            DB::table('ticket_sequences')->where('year', $year)->update(['last_value' => $siguiente]);

            return $siguiente;
        });
    }
}

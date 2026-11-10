<?php

/*
 * Equipo interno de Ling Marco. Qué es: dónde trabaja cada AGENTE (el administrador no tiene equipo).
 * Cuándo tocarlo: si Ling Marco crea un equipo nuevo; tocar también el CHECK de `users.team` (migración nueva,
 * como 2026_11_10_300000_add_accounting_team) y User::workableDepartments().
 * «Contabilidad» (2026-11-10) atiende SOLO el departamento Contabilidad (valor guardado `other`).
 * No confundir con Department: el departamento es lo que elige el cliente; el equipo es quien lo atiende.
 */

namespace App\Enums;

enum Team: string
{
    case Support = 'support';
    case Applications = 'applications';
    case Accounting = 'accounting';

    public function label(): string
    {
        return match ($this) {
            self::Support => 'Soporte',
            self::Applications => 'Desarrollo y Diseño',
            self::Accounting => 'Contabilidad',
        };
    }
}

<?php

/*
 * Equipo interno de Ling Marco. Qué es: dónde trabaja cada AGENTE (el administrador no tiene equipo).
 * Cuándo tocarlo: si Ling Marco crea un equipo nuevo; tocar también el CHECK de `users.team`.
 * No confundir con Department: el departamento es lo que elige el cliente; el equipo es quien lo atiende.
 */

namespace App\Enums;

enum Team: string
{
    case Support = 'support';
    case Applications = 'applications';

    public function label(): string
    {
        return match ($this) {
            self::Support => 'Soporte',
            self::Applications => 'Desarrollo y Diseño',
        };
    }
}

<?php

/*
 * Rol de una cuenta. Qué es: los 4 roles de la aplicación (2 de cliente, 2 internos).
 * Cuándo tocarlo: solo si cambia el modelo de roles; hay que tocar también el CHECK de
 * `users` (migración) y las policies. EnumsMatchDatabaseTest avisa si se olvida el CHECK.
 *
 * Nota: el antiguo «supervisor» NO existe; se fusionó con el administrador (decidido 2026-11-09).
 */

namespace App\Enums;

enum UserRole: string
{
    case CompanyUser = 'company_user';
    case CompanyAdmin = 'company_admin';
    case Agent = 'agent';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::CompanyUser => 'Usuario',
            self::CompanyAdmin => 'Administrador de empresa',
            self::Agent => 'Agente',
            self::Admin => 'Administrador',
        };
    }

    /** Empleado de Ling Marco (no pertenece a ninguna empresa cliente). */
    public function isInternal(): bool
    {
        return $this === self::Agent || $this === self::Admin;
    }

    /** Cuenta de una empresa cliente. */
    public function isCompany(): bool
    {
        return ! $this->isInternal();
    }
}

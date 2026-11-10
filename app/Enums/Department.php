<?php

/*
 * Departamento de un ticket. Qué es: la etiqueta que elige el cliente al abrir el ticket.
 * Cuándo tocarlo: casi nunca. Son 4 y fijos (llevan los colores del logo y definen quién ve qué,
 * R13). Si cambia, tocar también los CHECK de `tickets.department` y `categories.department`.
 *
 * `team()` es la tabla de correspondencia del encuadre: qué equipo interno atiende cada
 * departamento. «Otros» no tiene equipo: lo ve y lo responde solo el administrador (R13).
 */

namespace App\Enums;

enum Department: string
{
    case Support = 'support';
    case WebApps = 'web_apps';
    case Websites = 'websites';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Support => 'Soporte',
            self::WebApps => 'Aplicaciones',
            self::Websites => 'Páginas web',
            self::Other => 'Otros',
        };
    }

    /** Clase CSS de la etiqueta de color (public/css/panel.css). El color nunca va solo: siempre junto a `label()`. */
    public function cssClass(): string
    {
        return match ($this) {
            self::Support => 'soporte',
            self::WebApps => 'apps',
            self::Websites => 'webs',
            self::Other => 'otros',
        };
    }

    /** Equipo que lo atiende; null = solo el administrador (R13). */
    public function team(): ?Team
    {
        return match ($this) {
            self::Support => Team::Support,
            self::WebApps, self::Websites => Team::Applications,
            self::Other => null,
        };
    }
}

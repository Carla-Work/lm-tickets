<?php

/*
 * Prioridad interna de un ticket: Baja · Normal · Alta · Urgente. Valores guardados en inglés, repetidos en el CHECK
 * `tickets_priority_check` de la base (si se añade uno, hay que migrar el CHECK).
 * Cuándo tocarlo: al cambiar las prioridades (decisión de la usuaria, mockups P1 validados 2026-11-11).
 *
 * Trampa: es INTERNA. El cliente no la elige ni la ve en ninguna pantalla, correo o aviso; si algún día se le
 * enseña, hay que cambiar `docs/plan/p1-prioridades-y-etiquetas.md` y su test de ocultación.
 */

namespace App\Enums;

enum Priority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Baja',
            self::Normal => 'Normal',
            self::High => 'Alta',
            self::Urgent => 'Urgente',
        };
    }

    /** Texto de la etiqueta en las listas. «Normal» no se pinta (es lo habitual). */
    public function badge(): string
    {
        return match ($this) {
            self::Low => 'Baja',
            self::Normal => 'Normal',
            self::High => '▲ Alta',
            self::Urgent => '▲▲ Urgente',
        };
    }

    public function cssClass(): string
    {
        return match ($this) {
            self::Low => 'baja',
            self::Normal => '',
            self::High => 'alta',
            self::Urgent => 'urgente',
        };
    }
}

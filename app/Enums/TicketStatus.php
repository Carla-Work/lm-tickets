<?php

/*
 * Estado de un ticket. Qué es: los 5 estados del encuadre.
 * Cuándo tocarlo: si cambia el ciclo de vida; tocar también el CHECK de `tickets.status` y
 * las reglas R4, R14, R15, R16.
 *
 * Trampa: `Resuelto` y `Cerrado` son finales para el cliente (no escribe ni reabre, R4/R15), pero
 * NO son lo mismo: en `Resuelto` el agente aún puede escribir mensajes y notas y cerrar;
 * `Cerrado` es el final de verdad.
 */

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case PendingCustomer = 'pending_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /** Nombre interno (panel del equipo). */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierto',
            self::InProgress => 'En curso',
            self::PendingCustomer => 'Pendiente de cliente',
            self::Resolved => 'Resuelto',
            self::Closed => 'Cerrado',
        };
    }

    /** Nombre que ve el propio solicitante: «de cliente» es lenguaje interno. */
    public function customerLabel(): string
    {
        return $this === self::PendingCustomer ? 'Pendiente de tu respuesta' : $this->label();
    }

    /** Ya no admite mensajes del cliente ni puede reabrirse (R4, R15). */
    public function isFinal(): bool
    {
        return $this === self::Resolved || $this === self::Closed;
    }
}

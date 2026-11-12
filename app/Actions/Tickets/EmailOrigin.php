<?php

/*
 * Datos de un ticket que nace de un CORREO RECIBIDO (P1, docs/plan/p1-email-entrante.md). Cuándo tocarlo: al cambiar qué se hereda del correo.
 * Es un paquete de datos para CreateTicket: el Message-ID del correo (queda en el primer mensaje, para que las respuestas entren en el hilo)
 * y, si el correo iba dirigido a un compañero que puede atender el departamento, a quién se asigna. No decide nada por sí mismo.
 */

namespace App\Actions\Tickets;

use App\Models\User;

final class EmailOrigin
{
    public function __construct(public readonly string $messageId, public readonly ?User $assignee = null) {}
}

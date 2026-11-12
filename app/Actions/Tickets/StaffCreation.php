<?php

/*
 * Datos de un ticket que una PERSONA DEL EQUIPO crea a nombre de un cliente (P1, docs/plan/p1-ticket-creado-por-agente.md).
 * Cuándo tocarlo: al cambiar qué decide quien crea el ticket (canal, asignación, aviso al cliente).
 *
 * Qué es: un simple paquete de datos que CreateTicket recibe cuando el ticket no lo abre el cliente. Sin él (`null`), el ticket
 * lo abrió el propio solicitante (canal web, sin asignar). No hace comprobaciones: las hace CreateTicket con el ticket en su
 * transacción (permiso, departamento que puede atender, persona asignable, cliente operativo).
 */

namespace App\Actions\Tickets;

use App\Enums\TicketChannel;
use App\Models\User;

final class StaffCreation
{
    public function __construct(
        public readonly User $actor,            // quien lo crea: agente o administrador
        public readonly TicketChannel $channel, // cómo llegó (teléfono, en persona…); interno
        public readonly User $assignee,         // OBLIGATORIO: el ticket nace «Abierto» y asignado a un agente que lo pueda atender
        public readonly bool $notifyCustomer,   // ¿se avisa al cliente de que se le abrió un ticket? lo decide quien lo crea
    ) {}
}

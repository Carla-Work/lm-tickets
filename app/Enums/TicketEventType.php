<?php

/*
 * Tipo de evento del historial (R5). Qué es: qué cambió en un ticket.
 * Cuándo tocarlo: al registrar un cambio nuevo. Es una columna de texto SIN CHECK a propósito:
 * el historial solo se añade y conviene poder incorporar tipos sin migrar.
 */

namespace App\Enums;

enum TicketEventType: string
{
    case Created = 'created';
    case CreatedByStaff = 'created_by_staff'; // INTERNO: quién lo creó a nombre del cliente, canal y a quién se asignó
    case StatusChanged = 'status_changed';
    case Assigned = 'assigned';
    case DepartmentChanged = 'department_changed';
    case PriorityChanged = 'priority_changed';
    case TagsChanged = 'tags_changed';
    case Rated = 'rated';
    case Resolved = 'resolved';
    case Closed = 'closed';
}

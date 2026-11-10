<?php

/*
 * Tipos de aviso de un ticket (la campana y el correo). Cuándo tocarlo: al añadir un aviso nuevo; el valor se
 * guarda en `notifications.data.kind` y la bandeja lo usa para marcar «Respuesta del cliente».
 */

namespace App\Enums;

enum TicketNoticeKind: string
{
    // --- para el CLIENTE ---
    case Received = 'received';                 // «Hemos recibido tu ticket»
    case Replied = 'replied';                   // el equipo ha respondido
    case StatusChanged = 'status_changed';      // en curso / pendiente de ti / resuelto / cerrado
    case DepartmentChanged = 'department_changed'; // R12

    // --- para el EQUIPO ---
    case NewUnassigned = 'new_unassigned';      // ticket sin persona asignada que le llega al equipo
    case ClientReplied = 'client_replied';      // el cliente ha respondido
    case ClientResolved = 'client_resolved';    // el cliente lo ha marcado como resuelto (a cerrar por un agente)
    case Assigned = 'assigned';                 // «te han asignado»
}

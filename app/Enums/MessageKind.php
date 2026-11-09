<?php

/*
 * Tipo de mensaje del hilo. Qué es: público (lo ve el cliente) o nota interna (solo el equipo, R2).
 * Cuándo tocarlo: casi nunca; tocar también el CHECK de `ticket_messages.kind`.
 * Trampa: añadir un tipo nuevo SIN decidir quién lo ve es abrir una fuga de datos.
 */

namespace App\Enums;

enum MessageKind: string
{
    case Public = 'public';
    case Internal = 'internal';
}

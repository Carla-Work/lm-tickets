<?php

/*
 * Canal por el que llegó un ticket. Qué es: dato INTERNO (el cliente no lo ve en ninguna pantalla ni correo, como la prioridad).
 * Cuándo tocarlo: al añadir un canal; los valores están repetidos en el CHECK `tickets_channel_check` (migración
 * 2026_11_12_100000): hay que migrarlos a la vez.
 *
 * `Web` y `Email` los pone el sistema (el cliente abre el ticket en el panel / entra por correo, P1 «correo entrante»);
 * los otros tres los elige la persona del equipo al crear un ticket a nombre de un cliente.
 */

namespace App\Enums;

enum TicketChannel: string
{
    case Web = 'web';
    case Email = 'email';
    case Phone = 'phone';
    case InPerson = 'in_person';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Panel web',
            self::Email => 'Correo',
            self::Phone => 'Teléfono',
            self::InPerson => 'En persona',
            self::Other => 'Otro',
        };
    }

    /**
     * Los canales que puede elegir el EQUIPO al crear un ticket (el formulario y la validación salen de aquí). «Web» queda
     * fuera: un ticket que crea el equipo nunca llegó por el panel del cliente.
     *
     * @return list<self>
     */
    public static function forStaff(): array
    {
        return [self::Phone, self::InPerson, self::Email, self::Other];
    }
}

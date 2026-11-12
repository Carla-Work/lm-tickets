<?php

/*
 * Cómo se leen los correos de un buzón. Cuándo tocarlo: nunca por lógica de negocio; solo si cambia el modo de leer.
 * Hay una implementación real (ImapMailboxClient) y los tests usan una falsa: ningún test habla con un servidor de verdad.
 */

namespace App\Support\Inbound;

use Carbon\CarbonInterface;

interface MailboxClient
{
    /**
     * Correos del buzón recibidos desde `$since`. NO debe cambiar nada en el buzón (ni marcar como leído, ni borrar, ni mover):
     * personas lo leen a la vez.
     *
     * @param  array<string, mixed>  $mailbox  una entrada de config('inbound.mailboxes')
     * @return iterable<IncomingEmail>
     */
    public function fetch(array $mailbox, CarbonInterface $since): iterable;
}

<?php

/*
 * Un correo recibido, ya leído y normalizado. Cuándo tocarlo: al cambiar qué datos del correo se usan.
 * Es un paquete de datos: no hace nada. Lo produce un MailboxClient y lo consume ProcessIncomingEmail (así los tests no necesitan un servidor IMAP).
 *
 * Trampas:
 *  - TODO viene de un remitente que no controlamos: nada se pinta sin escapar ni se usa como HTML.
 *  - `authResults` es la cabecera `Authentication-Results` que añadió NUESTRO servidor de correo al recibirlo (nunca una que traiga el remitente
 *    por su cuenta: un atacante podría escribirse un «spf=pass»). A comprobar con el proveedor que la añade (ver preflight y la guía de producción).
 */

namespace App\Support\Inbound;

use Carbon\CarbonInterface;

final class IncomingEmail
{
    /**
     * @param  list<string>  $references  Message-ID de los correos anteriores del hilo (sin < >)
     * @param  list<string>  $recipients  Para y CC (direcciones, en minúsculas)
     * @param  list<IncomingAttachment>  $attachments
     */
    public function __construct(
        public readonly string $messageId,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly array $recipients,
        public readonly string $subject,
        public readonly string $text,
        public readonly CarbonInterface $receivedAt,
        public readonly ?string $authResults = null,
        public readonly bool $automatic = false, // autorrespuesta, lista de correo o rebote: no lo escribió una persona
        public readonly array $attachments = [],
    ) {}

    /** Message-ID de los correos a los que responde este (sin < >), para buscar el hilo. @return list<string> */
    public function threadIds(): array
    {
        return array_values(array_unique(array_filter([$this->inReplyTo, ...$this->references])));
    }

    public function fromDomain(): string
    {
        return strtolower(substr(strrchr($this->fromEmail, '@') ?: '', 1));
    }

    /** Borra los ficheros temporales de los adjuntos (siempre al terminar, haya ido bien o no). */
    public function cleanup(): void
    {
        foreach ($this->attachments as $attachment) {
            @unlink($attachment->path);
        }
    }
}

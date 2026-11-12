<?php

/*
 * Ayudantes para los tests del correo entrante: construir un IncomingEmail y un buzón falso. Cuándo tocarlo: al cambiar IncomingEmail.
 * Ningún test habla con un servidor IMAP: el cliente real se sustituye por FakeMailboxClient (ver ImapMailboxClientTest para la lectura de correos reales).
 */

namespace Tests\Concerns;

use App\Support\Inbound\IncomingAttachment;
use App\Support\Inbound\IncomingEmail;
use App\Support\Inbound\MailboxClient;
use Carbon\CarbonInterface;

trait BuildsIncomingEmails
{
    protected int $mailCounter = 0;

    /** Autenticación correcta tal y como la escribiría NUESTRO servidor al recibir. */
    protected const AUTH_OK = 'mx.ionos.es; spf=pass smtp.mailfrom=cliente.es; dkim=pass header.d=cliente.es';

    /** @param  array<string, mixed>  $o */
    protected function incoming(array $o = []): IncomingEmail
    {
        $this->mailCounter++;

        return new IncomingEmail(
            messageId: $o['messageId'] ?? "msg-{$this->mailCounter}@cliente.es",
            inReplyTo: $o['inReplyTo'] ?? null,
            references: $o['references'] ?? [],
            fromEmail: $o['from'] ?? 'usuario.a@empresa-a.test',
            fromName: $o['fromName'] ?? 'Persona de prueba',
            recipients: $o['to'] ?? ['aplicaciones@lingmarco.es'],
            subject: $o['subject'] ?? 'Error al emitir la factura',
            text: $o['text'] ?? 'Desde ayer sale un error al emitir.',
            receivedAt: $o['receivedAt'] ?? now(),
            authResults: array_key_exists('auth', $o) ? $o['auth'] : self::AUTH_OK,
            automatic: $o['automatic'] ?? false,
            attachments: $o['attachments'] ?? [],
        );
    }

    /** Un adjunto real volcado a un fichero temporal (el contenido decide el tipo, no el nombre). */
    protected function incomingAttachment(string $name, string $content): IncomingAttachment
    {
        $path = tempnam(sys_get_temp_dir(), 'lmt');
        file_put_contents($path, $content);

        return new IncomingAttachment($name, $path);
    }

    protected function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    /** @return array<string, mixed> el buzón de config/inbound.php con esa clave (apps, support, accounting) */
    protected function mailbox(string $key = 'apps'): array
    {
        foreach (config('inbound.mailboxes') as $mailbox) {
            if ($mailbox['key'] === $key) {
                return $mailbox;
            }
        }
        throw new \RuntimeException("Buzón desconocido: {$key}");
    }
}

/** Buzón falso: devuelve los correos que se le den, por buzón, o lanza el error indicado. */
class FakeMailboxClient implements MailboxClient
{
    /** @var array<string, list<IncomingEmail>> */
    public array $emails = [];

    /** @var array<string, \Throwable> */
    public array $failures = [];

    public int $calls = 0;

    public function fetch(array $mailbox, CarbonInterface $since): iterable
    {
        $this->calls++;
        if (isset($this->failures[$mailbox['key']])) {
            throw $this->failures[$mailbox['key']];
        }

        return $this->emails[$mailbox['key']] ?? [];
    }
}

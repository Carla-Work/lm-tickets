<?php

/*
 * Lee un buzón por IMAP con webklex/php-imap (única dependencia nueva de esta función; aprobada por la usuaria, 2026-11-12).
 * Cuándo tocarla: al cambiar cómo se lee un correo (cabeceras, adjuntos) o la conexión.
 *
 * Trampas:
 *  - Los buzones son COMPARTIDOS con personas: se lee con `leaveUnread()` (no marca como leído), sin borrar ni mover nada. Qué correos ya se procesaron
 *    se apunta en nuestra base (`inbound_emails`), no en el buzón. Se piden solo los de los últimos días (`since`).
 *  - `map()` es público y se prueba con correos reales en texto (ImapMailboxClientTest) porque la conexión a un servidor no se puede probar en los tests.
 *    ⚠ La conexión real (`fetch`) NO está probada contra un servidor IMAP de verdad: probarla con el buzón de IONOS antes de dar la función por buena.
 *  - La cabecera Authentication-Results que cuenta es la PRIMERA (la que añadió nuestro servidor al recibir); un remitente puede escribirse otra más abajo.
 *  - Los adjuntos se vuelcan a ficheros temporales (IncomingEmail::cleanup() los borra); el contenido nunca se ejecuta ni se confía en su nombre.
 */

namespace App\Support\Inbound;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

class ImapMailboxClient implements MailboxClient
{
    public function fetch(array $mailbox, CarbonInterface $since): iterable
    {
        $client = (new ClientManager)->make([
            'host' => $mailbox['host'],
            'port' => $mailbox['port'],
            'encryption' => $mailbox['encryption'] ?: false,
            'validate_cert' => true,
            'username' => $mailbox['username'],
            'password' => $mailbox['password'],
            'protocol' => 'imap',
        ]);
        $client->connect();

        try {
            $folder = $client->getFolderByPath((string) ($mailbox['folder'] ?? 'INBOX'));
            if ($folder === null) {
                throw new \RuntimeException("No existe la carpeta del buzón {$mailbox['key']}.");
            }

            // leaveUnread(): no tocar los indicadores del buzón compartido. since: solo los últimos días.
            $messages = $folder->query()->since($since->copy()->startOfDay())->leaveUnread()->fetchOrderAsc()->get();

            foreach ($messages as $message) {
                yield self::map($message);
            }
        } finally {
            $client->disconnect();
        }
    }

    public static function map(Message $message): IncomingEmail
    {
        $raw = (string) ($message->getHeader()->raw ?? '');
        $from = $message->getFrom()->first();
        $recipients = [];
        foreach ([$message->getTo(), $message->getCc()] as $list) {
            foreach ($list->all() as $address) {
                if ($address->mail !== '') {
                    $recipients[] = strtolower($address->mail);
                }
            }
        }

        $text = trim($message->getTextBody());
        if ($text === '') {
            $text = trim(html_entity_decode(strip_tags((string) preg_replace('/<(br|\/p|\/div)[^>]*>/i', "\n", $message->getHTMLBody())), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $date = $message->getDate()->first();

        return new IncomingEmail(
            messageId: self::cleanId((string) $message->getMessageId()) ?: 'sin-id-'.sha1($raw.$text),
            inReplyTo: self::cleanId((string) $message->getInReplyTo()) ?: null,
            references: self::splitIds((string) $message->getReferences()),
            fromEmail: strtolower($from->mail ?? ''),
            fromName: ($from->personal ?? '') !== '' ? $from->personal : null,
            recipients: array_values(array_unique($recipients)),
            subject: trim((string) $message->getSubject()),
            text: $text,
            receivedAt: $date instanceof \DateTimeInterface ? Carbon::instance($date) : now(),
            authResults: self::firstHeader($raw, 'Authentication-Results'),
            automatic: self::isAutomatic($raw, strtolower($from->mail ?? '')),
            attachments: self::attachments($message),
        );
    }

    /** @return list<IncomingAttachment> */
    private static function attachments(Message $message): array
    {
        $files = [];
        foreach ($message->getAttachments() as $attachment) {
            $name = trim((string) $attachment->getName());
            if ($name === '') {
                continue; // partes sin nombre (firmas, imágenes incrustadas): no son adjuntos que alguien haya querido mandar
            }
            $path = tempnam(sys_get_temp_dir(), 'lmin');
            if ($path === false) {
                continue;
            }
            file_put_contents($path, (string) $attachment->getContent());
            $files[] = new IncomingAttachment($name, $path);
        }

        return $files;
    }

    /** Primera cabecera con ese nombre (la de más arriba), con sus líneas plegadas unidas. */
    public static function firstHeader(string $raw, string $name): ?string
    {
        $raw = str_replace("\r\n", "\n", $raw);
        if (preg_match('/^'.preg_quote($name, '/').':[ \t]*(.*(?:\n[ \t]+.*)*)/im', $raw, $m)) {
            return trim((string) preg_replace('/\s*\n\s*/', ' ', $m[1]));
        }

        return null;
    }

    /** ¿Lo escribió una máquina (autorrespuesta, lista, rebote)? Nunca se le crea ticket ni se le contesta. */
    public static function isAutomatic(string $rawHeaders, string $fromEmail): bool
    {
        $auto = strtolower((string) self::firstHeader($rawHeaders, 'Auto-Submitted'));
        $precedence = strtolower((string) self::firstHeader($rawHeaders, 'Precedence'));
        $returnPath = trim((string) self::firstHeader($rawHeaders, 'Return-Path'));

        return ($auto !== '' && $auto !== 'no')
            || in_array($precedence, ['bulk', 'junk', 'list', 'auto_reply'], true)
            || self::firstHeader($rawHeaders, 'X-Auto-Response-Suppress') !== null
            || self::firstHeader($rawHeaders, 'List-Id') !== null
            || $returnPath === '<>'
            || $fromEmail === ''
            || (bool) preg_match('/^(mailer-daemon|postmaster|no-?reply|do-?not-?reply)@/', $fromEmail);
    }

    public static function cleanId(string $id): string
    {
        return trim($id, " \t\n\r<>");
    }

    /**
     * La librería entrega las referencias ya sin «< >» y separadas por comas; se aceptan también con ellos.
     *
     * @return list<string>
     */
    private static function splitIds(string $references): array
    {
        $ids = array_map(self::cleanId(...), preg_split('/[\s,]+/', trim($references)) ?: []);

        return array_values(array_unique(array_filter($ids)));
    }
}

<?php

/*
 * La LECTURA de correos con webklex/php-imap, probada con correos reales en texto (la conexión a un servidor no se puede probar en los tests).
 * Cuándo tocarlo: al cambiar ImapMailboxClient::map. Lo que vigila: remitente y destinatarios, hilo (In-Reply-To/References), adjuntos, texto, y que la
 * autenticación que cuenta es la PRIMERA cabecera Authentication-Results (la de nuestro servidor), no una que se escriba el remitente.
 * ⚠ Esto NO prueba `fetch` contra un servidor IMAP de verdad: hay que probarlo con el buzón de IONOS (ver la bitácora).
 */

namespace Tests\Feature\Inbound;

use App\Support\Inbound\EmailAuthentication;
use App\Support\Inbound\ImapMailboxClient;
use App\Support\Inbound\IncomingEmail;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;
use Webklex\PHPIMAP\Message;

class ImapMailboxClientTest extends TestCase
{
    private function raw(array $extra = [], string $body = "Buenos días: no puedo fichar. Un saludo\r\n", ?string $contentType = null): string
    {
        $headers = array_merge([
            'Return-Path' => '<luis.perez@hermanossantana.es>',
            'Authentication-Results' => 'mx.ionos.es; spf=pass smtp.mailfrom=hermanossantana.es; dkim=pass header.d=hermanossantana.es',
            'From' => '=?UTF-8?Q?Luis_P=C3=A9rez?= <Luis.Perez@HermanosSantana.es>',
            'To' => 'aplicaciones@lingmarco.es, Francisco Morales <francisco@lingmarco.es>',
            'Cc' => 'otra@x.com',
            'Subject' => '=?UTF-8?Q?Error_al_fichar_desde_el_m=C3=B3vil?=',
            'Date' => 'Thu, 12 Nov 2026 09:12:00 +0000',
            'Message-ID' => '<abc123@hermanossantana.es>',
            'MIME-Version' => '1.0',
            'Content-Type' => $contentType ?? 'text/plain; charset=UTF-8',
        ], $extra);
        $text = '';
        foreach ($headers as $name => $value) {
            $text .= "{$name}: {$value}\r\n";
        }

        return $text."\r\n".$body;
    }

    private function map(string $raw): IncomingEmail
    {
        return ImapMailboxClient::map(Message::fromString($raw));
    }

    #[TestDox('Lee remitente (con tildes y mayúsculas), destinatarios Para y CC, asunto, texto, fecha e identificador')]
    public function test_it_reads_the_basics(): void
    {
        $e = $this->map($this->raw());

        $this->assertSame('abc123@hermanossantana.es', $e->messageId);
        $this->assertSame('luis.perez@hermanossantana.es', $e->fromEmail, 'en minúsculas');
        $this->assertSame('Luis Pérez', $e->fromName);
        $this->assertSame(['aplicaciones@lingmarco.es', 'francisco@lingmarco.es', 'otra@x.com'], $e->recipients);
        $this->assertSame('Error al fichar desde el móvil', $e->subject);
        $this->assertStringContainsString('no puedo fichar', $e->text);
        $this->assertSame('2026-11-12 09:12:00', $e->receivedAt->toDateTimeString());
        $this->assertFalse($e->automatic);
        $this->assertSame('hermanossantana.es', $e->fromDomain());
    }

    #[TestDox('Lee el hilo: In-Reply-To y References sin «< >», que son los Message-ID que enlazan con nuestros correos')]
    public function test_it_reads_the_thread(): void
    {
        $e = $this->map($this->raw(['In-Reply-To' => '<tk-1-m2@lingmarco.es>', 'References' => '<tk-1-m1@lingmarco.es> <tk-1-m2@lingmarco.es>']));

        $this->assertSame('tk-1-m2@lingmarco.es', $e->inReplyTo);
        $this->assertSame(['tk-1-m1@lingmarco.es', 'tk-1-m2@lingmarco.es'], $e->references);
        $this->assertEqualsCanonicalizing(['tk-1-m1@lingmarco.es', 'tk-1-m2@lingmarco.es'], $e->threadIds(), 'sin repetidos');
    }

    #[TestDox('La autenticación que cuenta es la PRIMERA cabecera Authentication-Results: un remitente que se escribe otra «spf=pass» más abajo no engaña')]
    public function test_only_the_first_authentication_header_counts(): void
    {
        $raw = $this->raw(['Authentication-Results' => 'mx.ionos.es; spf=fail; dkim=none']);
        $raw = str_replace('From:', "Authentication-Results: evil; spf=pass; dkim=pass\r\nFrom:", $raw);

        $e = $this->map($raw);

        $this->assertStringContainsString('spf=fail', (string) $e->authResults);
        $this->assertFalse(EmailAuthentication::passes($e->authResults));
    }

    #[TestDox('Detecta autorrespuestas, listas y rebotes (Auto-Submitted, Precedence, List-Id, Return-Path vacío, no-reply, mailer-daemon)')]
    public function test_it_detects_automatic_mail(): void
    {
        foreach ([['Auto-Submitted' => 'auto-replied'], ['Precedence' => 'bulk'], ['List-Id' => '<lista.x.com>'], ['Return-Path' => '<>'], ['From' => 'No Reply <no-reply@x.com>'], ['From' => 'MAILER-DAEMON@x.com']] as $extra) {
            $this->assertTrue($this->map($this->raw($extra))->automatic, json_encode($extra));
        }
        $this->assertFalse($this->map($this->raw(['Auto-Submitted' => 'no']))->automatic, '«no» significa escrito por una persona');
    }

    #[TestDox('Lee los adjuntos con su nombre a ficheros temporales (cleanup los borra) e ignora las partes sin nombre')]
    public function test_it_reads_attachments(): void
    {
        $body = "--b1\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nTexto\r\n--b1\r\nContent-Type: image/png; name=\"captura.png\"\r\nContent-Disposition: attachment; filename=\"captura.png\"\r\nContent-Transfer-Encoding: base64\r\n\r\niVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==\r\n--b1--\r\n";

        $e = $this->map($this->raw(body: $body, contentType: 'multipart/mixed; boundary="b1"'));

        $this->assertCount(1, $e->attachments);
        $this->assertSame('captura.png', $e->attachments[0]->name);
        $this->assertFileExists($e->attachments[0]->path);
        $this->assertSame('image/png', mime_content_type($e->attachments[0]->path));
        $path = $e->attachments[0]->path;
        $e->cleanup();
        $this->assertFileDoesNotExist($path);
    }

    #[TestDox('Un correo solo en HTML se convierte a texto plano: nunca se guarda ni se pinta el HTML del remitente')]
    public function test_html_only_mail_becomes_plain_text(): void
    {
        $e = $this->map($this->raw(body: "<html><body><p>Hola</p><script>alert(1)</script><p>Esto <b>falla</b></p></body></html>\r\n", contentType: 'text/html; charset=UTF-8'));

        $this->assertStringNotContainsString('<p>', $e->text);
        $this->assertStringNotContainsString('<b>', $e->text);
        $this->assertStringContainsString('Esto falla', $e->text);
    }
}

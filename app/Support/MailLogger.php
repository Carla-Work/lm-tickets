<?php

/*
 * Escribe el registro de correos (EmailLog) a partir de los eventos de envío de Laravel.
 * Cuándo tocarlo: al cambiar qué se guarda de cada correo. Se engancha en AppServiceProvider.
 *
 * Por qué la fila se abre ANTES de enviar: Laravel solo avisa de los envíos correctos (MessageSent). Si solo
 * se escribiera entonces, un correo perdido no dejaría rastro y la lista parecería completa. Se abre en
 * «pending» y se cierra en «sent»; lo que se queda «pending» es lo que nunca se confirmó. El motivo del fallo
 * solo se conoce en la cola (JobFailed), donde se marcan como «failed» los abiertos por este proceso.
 *
 * Trampas:
 *  - WITHOUT_BODY: invitación y recuperación llevan un enlace de un solo uso; su cuerpo NO se guarda.
 *    Si añades un correo con un enlace que entra en una cuenta, añádelo ahí.
 *  - Un fallo al registrar NO debe impedir el envío: se informa con report() y se sigue.
 */

namespace App\Support;

use App\Models\EmailLog;
use App\Notifications\InvitationNotification;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Events\JobFailed;
use Symfony\Component\Mime\Address;
use Throwable;

class MailLogger
{
    /** Cabecera con la que el mensaje lleva el id de su fila (el Message-Id lo pone el transporte, más tarde). */
    private const HEADER = 'X-App-Log';

    /** Los correos con esta cabecera llevan el texto de un ticket (dato de cliente): el registro NO guarda su cuerpo. */
    public const NO_BODY_HEADER = 'X-App-No-Body';

    /** Correos cuyo cuerpo NO se guarda (llevan un enlace de un solo uso). */
    public const WITHOUT_BODY = [InvitationNotification::class, ResetPasswordNotification::class];

    /** @var array<int, int> ids abiertos por este proceso y aún sin confirmar */
    private array $inFlight = [];

    public function sending(MessageSending $event): void
    {
        try {
            $message = $event->message;
            $kind = $event->data['__laravel_notification'] ?? null;

            $log = EmailLog::create([
                'status' => EmailLog::PENDING,
                'subject' => $message->getSubject(),
                'from_email' => $this->addresses($message->getFrom()),
                'to_emails' => $this->addresses($message->getTo()),
                'kind' => $kind,
                // Sin cuerpo: los correos con enlace de un solo uso y los que llevan el TEXTO de un ticket (cabecera NO_BODY_HEADER, ver TicketMailer).
                'body' => in_array($kind, self::WITHOUT_BODY, true) || $message->getHeaders()->has(self::NO_BODY_HEADER) ? null : $message->getHtmlBody(),
            ]);

            $this->inFlight[$log->id] = $log->id;
            $message->getHeaders()->addTextHeader(self::HEADER, (string) $log->id);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function sent(MessageSent $event): void
    {
        try {
            $id = (int) $event->message->getHeaders()->getHeaderBody(self::HEADER);
            if ($id > 0) {
                EmailLog::query()->whereKey($id)->update(['status' => EmailLog::SENT, 'sent_at' => now()]);
                unset($this->inFlight[$id]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function failed(JobFailed $event): void
    {
        try {
            if ($this->inFlight !== []) {
                EmailLog::query()->whereKey($this->inFlight)->where('status', EmailLog::PENDING)
                    ->update(['status' => EmailLog::FAILED, 'error' => mb_substr($event->exception->getMessage(), 0, 1000)]);
                $this->inFlight = [];
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @param  array<int, Address>  $list */
    private function addresses(array $list): ?string
    {
        $out = implode(', ', array_map(fn (Address $a) => $a->getAddress(), $list));

        return $out === '' ? null : mb_substr($out, 0, 1000);
    }
}

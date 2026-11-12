<?php

/*
 * Procesa UN correo recibido en un buzón compartido y decide qué se hace con él (P1, docs/plan/p1-email-entrante.md).
 * Cuándo tocarla: al cambiar las reglas del filtro de entrada. Es el sitio ÚNICO donde un correo se convierte en ticket, en mensaje o en cuarentena.
 *
 * El filtro (decidido con la usuaria, 2026-11-12: «no todo correo es una incidencia»). Cada correo acaba en UNO de estos sitios:
 *  1. IGNORADO: autorrespuesta, lista, rebote, o remitente del propio equipo (@lingmarco.es). Nunca crea nada.
 *  2. MENSAJE de un ticket: responde a un correo NUESTRO (cabecera In-Reply-To/References → `ticket_messages.email_message_id`), lo envía el SOLICITANTE y pasa
 *     SPF/DKIM. Aunque sea corto («gracias»). Si el ticket está resuelto/cerrado: rebote automático (R4/R15) y nada se añade.
 *  3. TICKET: correo NUEVO (no es respuesta) de un cliente conocido, activo, que pasa SPF/DKIM y PARECE una incidencia (palabra clave de producto o de incidencia).
 *  4. CUARENTENA «pendiente» (90 días): cliente conocido que no parece incidencia, correo que no pasa SPF/DKIM, respuesta de quien no es el solicitante, o remitente
 *     desconocido con alguna pista (dominio de una empresa, palabra clave, palabra de incidencia). Decide el administrador.
 *  5. «POSIBLE SPAM» (30 días): remitente desconocido sin ninguna pista.
 *
 * Trampas:
 *  - El departamento sale del BUZÓN, no del contenido. Un correo de comercial@ es de Contabilidad (R13): solo el equipo de Contabilidad lo atiende.
 *  - NUNCA se crea una cuenta (R18) ni se da por bueno un remitente solo por su dominio: el dominio es una pista para el administrador.
 *  - El producto solo se asigna si UNA palabra clave coincide con UN producto que la empresa del cliente tiene (R6/R7); con varios o ninguno, sin producto.
 *  - Si el correo iba (Para/CC) a un compañero que puede atender el departamento, el ticket nace asignado a él (primero que coincida).
 *  - Los adjuntos pasan por la misma lista cerrada que cualquier adjunto (AllowedAttachment); los que no, se anotan y se descartan, el correo se procesa igual.
 *  - Idempotente: un correo (Message-ID) se procesa UNA vez, aunque el comando se ejecute dos a la vez (clave única). Nada se cambia en el buzón.
 *  - Una nota interna nunca puede llegar aquí; esto solo habla del correo del CLIENTE (R2).
 */

namespace App\Actions\Inbound;

use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\EmailOrigin;
use App\Actions\Tickets\ReplyToTicket;
use App\Enums\Department;
use App\Enums\MessageKind;
use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyDomain;
use App\Models\InboundEmail;
use App\Models\InboundEmailAttachment;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Rules\AllowedAttachment;
use App\Support\Inbound\EmailAuthentication;
use App\Support\Inbound\IncomingEmail;
use App\Support\Inbound\KeywordMatcher;
use App\Support\Inbound\TicketMailer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ProcessIncomingEmail
{
    public function __construct(
        private readonly CreateTicket $createTicket,
        private readonly ReplyToTicket $replyToTicket,
        private readonly KeywordMatcher $keywords,
        private readonly TicketMailer $mailer,
    ) {}

    /** @param  array<string, mixed>  $mailbox  una entrada de config('inbound.mailboxes') */
    public function __invoke(IncomingEmail $mail, array $mailbox): InboundEmail
    {
        $existing = InboundEmail::query()->where('message_id', $mail->messageId)->first();
        if ($existing !== null) {
            $mail->cleanup();

            return $existing;
        }

        try {
            return $this->process($mail, $mailbox);
        } catch (UniqueConstraintViolationException) {
            // Otro proceso lo registró entre la comprobación y el guardado: ya está procesado, no se duplica.
            return InboundEmail::query()->where('message_id', $mail->messageId)->firstOrFail();
        } finally {
            $mail->cleanup();
        }
    }

    /** @param  array<string, mixed>  $mailbox */
    private function process(IncomingEmail $mail, array $mailbox): InboundEmail
    {
        $department = Department::from((string) $mailbox['department']);

        // 1. No es de un cliente.
        if ($mail->automatic) {
            return $this->record($mail, $mailbox, InboundEmail::IGNORED, ['Autorrespuesta, lista de correo o rebote: no lo escribió una persona.']);
        }
        if ($mail->fromDomain() === strtolower((string) config('inbound.internal_domain'))) {
            return $this->record($mail, $mailbox, InboundEmail::IGNORED, ['Remitente del propio equipo (compañero o aviso del sistema).']);
        }

        $authOk = EmailAuthentication::passes($mail->authResults);
        $customer = $this->findCustomer($mail->fromEmail);
        $thread = $this->findThread($mail);

        // 2. Responde a un hilo nuestro.
        if ($thread !== null) {
            return $this->handleThreadReply($mail, $mailbox, $thread, $customer, $authOk);
        }

        // 3-4. Correo nuevo de un cliente conocido.
        if ($customer !== null) {
            $matches = $this->keywords->matches($mail->subject."\n".$mail->text);
            $looksLikeIncident = $matches !== [] || $this->keywords->hasIncidentWord($mail->subject."\n".$mail->text);

            if (! $authOk) {
                return $this->quarantine($mail, $mailbox, InboundEmail::PENDING, ['Cliente conocido, pero '.$this->authReason($mail), 'Posible suplantación: no se crea ticket sin que lo revise el administrador.']);
            }
            if (! $looksLikeIncident) {
                return $this->quarantine($mail, $mailbox, InboundEmail::PENDING, ['Cliente conocido: '.$customer->name.' ('.$customer->company?->name.')', 'No parece una incidencia ni responde a un ticket.']);
            }

            return $this->createTicketFor($mail, $mailbox, $department, $customer, $matches);
        }

        // 5. Remitente desconocido (o cuenta no operativa): pistas.
        $reasons = [];
        $company = $this->companyByDomain($mail->fromDomain());
        if ($company !== null) {
            $reasons[] = 'Dominio de '.$company->name;
            $reasons[] = 'Sin cuenta';
        }
        $matches = $this->keywords->matches($mail->subject."\n".$mail->text);
        foreach ($matches as $productId => $keyword) {
            $reasons[] = 'Palabra clave «'.$keyword.'» → '.(Product::query()->find($productId)->name ?? 'producto');
        }
        if ($matches === [] && $this->keywords->hasIncidentWord($mail->subject."\n".$mail->text)) {
            $reasons[] = 'Contiene una palabra de incidencia';
        }
        if (! $authOk) {
            $reasons[] = $this->authReason($mail);
        }

        if ($company === null && $matches === [] && ! $this->keywords->hasIncidentWord($mail->subject."\n".$mail->text)) {
            return $this->quarantine($mail, $mailbox, InboundEmail::SPAM, ['Sin cliente, dominio ni palabras clave.', ...($authOk ? [] : [$this->authReason($mail)])]);
        }

        return $this->quarantine($mail, $mailbox, InboundEmail::PENDING, $reasons);
    }

    // ------------------------------------------------------------------ hilo

    /** @param  array<string, mixed>  $mailbox */
    private function handleThreadReply(IncomingEmail $mail, array $mailbox, Ticket $ticket, ?User $customer, bool $authOk): InboundEmail
    {
        if ($customer === null || $customer->id !== $ticket->requester_id) {
            return $this->quarantine($mail, $mailbox, InboundEmail::PENDING, ["Responde al ticket #{$ticket->number}, pero el remitente no es su solicitante."]);
        }
        if (! $authOk) {
            return $this->quarantine($mail, $mailbox, InboundEmail::PENDING, ["Responde al ticket #{$ticket->number}, pero ".$this->authReason($mail), 'Posible suplantación.']);
        }

        // R4/R15: ticket resuelto o cerrado → no se añade; rebote automático (una vez por ticket y día) y listo.
        if ($ticket->status->isFinal()) {
            $this->mailer->sendClosedBounce($ticket);

            return $this->record($mail, $mailbox, InboundEmail::REJECTED, ["Responde al ticket #{$ticket->number}, que ya está {$ticket->status->label()}. Se envió el aviso de «ticket cerrado»."], $ticket->id);
        }

        [$files, $notes] = $this->usableAttachments($mail);
        $body = $this->cleanReplyBody($mail->text).($notes === [] ? '' : "\n\n".implode("\n", $notes));

        try {
            ($this->replyToTicket)($customer, $ticket, $body, MessageKind::Public, $files, false, $mail->messageId);
        } catch (AuthorizationException) {
            // El ticket pasó a final entre medias (R4): mismo tratamiento que arriba.
            $this->mailer->sendClosedBounce($ticket->refresh());

            return $this->record($mail, $mailbox, InboundEmail::REJECTED, ["Responde al ticket #{$ticket->number}, que se cerró mientras tanto."], $ticket->id);
        }

        return $this->record($mail, $mailbox, InboundEmail::REPLY, ["Respuesta del solicitante al ticket #{$ticket->number}."], $ticket->id);
    }

    private function findThread(IncomingEmail $mail): ?Ticket
    {
        $ids = $mail->threadIds();
        if ($ids === []) {
            return null;
        }
        $message = TicketMessage::query()->whereIn('email_message_id', $ids)->latest('id')->first();

        return $message === null ? null : Ticket::query()->find($message->ticket_id);
    }

    /** Quita el texto citado de una respuesta (líneas «> …» y todo lo que sigue a «El … escribió:» / «On … wrote:»); si no queda nada, deja el original. */
    private function cleanReplyBody(string $text): string
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*(El .{5,200} escribi[oó]:|On .{5,200} wrote:|-{3,}\s*(Mensaje original|Original Message)\s*-{3,})\s*$/iu', $line)) {
                break;
            }
            if (str_starts_with(ltrim($line), '>')) {
                continue;
            }
            $kept[] = $line;
        }
        $clean = trim(implode("\n", $kept));

        return $clean === '' ? trim($text) : $clean;
    }

    // ------------------------------------------------------------------ ticket nuevo

    /**
     * @param  array<string, mixed>  $mailbox
     * @param  array<int, string>  $matches  producto → palabra
     */
    private function createTicketFor(IncomingEmail $mail, array $mailbox, Department $department, User $customer, array $matches): InboundEmail
    {
        $reasons = ['Cliente conocido: '.$customer->name.' ('.$customer->company?->name.')'];

        // R6/R7: el producto solo vale si es de la empresa del cliente y la coincidencia es única.
        $companyProducts = $customer->company?->products()->where('active', true)->pluck('products.id')->all() ?? [];
        $candidates = array_values(array_intersect(array_keys($matches), $companyProducts));
        $product = count($candidates) === 1 ? Product::query()->find($candidates[0]) : null;
        if ($product !== null) {
            $reasons[] = 'Producto «'.$product->name.'» por la palabra clave «'.$matches[$product->id].'».';
        }

        $assignee = $this->namedColleague($mail, $department);
        if ($assignee !== null) {
            $reasons[] = 'Asignado a '.$assignee->name.' (el correo iba dirigido a él).';
        }

        [$files, $notes] = $this->usableAttachments($mail);
        $body = $this->truncate(trim($mail->text), 10000 - 600).($notes === [] ? '' : "\n\n".implode("\n", $notes));

        $ticket = ($this->createTicket)(
            $customer, $department, $this->defaultCategory($department), $product,
            $this->cleanSubject($mail->subject), $body, $files, null, null, new EmailOrigin($mail->messageId, $assignee),
        );

        return $this->record($mail, $mailbox, InboundEmail::TICKET, $reasons, $ticket->id);
    }

    /** Un agente en activo que pueda atender el departamento y figure en Para/CC (el primero que coincida). */
    private function namedColleague(IncomingEmail $mail, Department $department): ?User
    {
        if ($mail->recipients === []) {
            return null;
        }
        $candidates = User::query()->where('role', UserRole::Agent->value)->whereIn('email', $mail->recipients)->orderBy('id')->get();

        return $candidates->first(fn (User $u) => $u->isOperational() && $u->canWorkOn($department));
    }

    /** Categoría por defecto del departamento (R6 la exige): «Sin clasificar», que el agente corrige después. Se crea si falta. */
    private function defaultCategory(Department $department): Category
    {
        $category = Category::query()->where('department', $department->value)->where('is_default', true)->first();
        if ($category !== null) {
            return $category;
        }

        $category = new Category(['name' => (string) config('inbound.default_category'), 'active' => true]);
        $category->forceFill(['department' => $department->value, 'is_default' => true])->save(); // `is_default` y `department` no son asignables en masa

        return $category;
    }

    private function cleanSubject(string $subject): string
    {
        $subject = trim((string) preg_replace('/^((re|rv|fw|fwd)\s*:\s*)+/i', '', trim($subject)));

        return $subject === '' ? '(sin asunto)' : mb_substr($subject, 0, 200);
    }

    // ------------------------------------------------------------------ adjuntos

    /**
     * Adjuntos que pasan la lista cerrada y los límites (los de siempre), como UploadedFile; y notas de los descartados para dejarlas en el texto.
     *
     * @return array{0: list<UploadedFile>, 1: list<string>}
     */
    private function usableAttachments(IncomingEmail $mail): array
    {
        $files = [];
        $notes = [];
        $total = 0;
        $limitTotal = (int) config('access.attachments.max_total_kb') * 1024;

        foreach ($mail->attachments as $attachment) {
            $file = $attachment->asUploadedFile();
            $validator = Validator::make(['f' => $file], ['f' => ['file', 'max:'.(int) config('access.attachments.max_kb'), new AllowedAttachment]]);
            $size = (int) $file->getSize();

            if ($validator->fails()) {
                $notes[] = '[Adjunto descartado: «'.$attachment->name.'» — '.$validator->errors()->first('f').']';
            } elseif (count($files) >= (int) config('access.attachments.max_files') || $total + $size > $limitTotal) {
                $notes[] = '[Adjunto descartado: «'.$attachment->name.'» — se superan los límites de adjuntos por mensaje]';
            } else {
                $files[] = $file;
                $total += $size;
            }
        }

        return [$files, $notes];
    }

    // ------------------------------------------------------------------ cuarentena y registro

    /**
     * @param  array<string, mixed>  $mailbox
     * @param  list<string>  $reasons
     */
    private function quarantine(IncomingEmail $mail, array $mailbox, string $status, array $reasons): InboundEmail
    {
        [$files, $notes] = $this->usableAttachments($mail);

        $email = $this->record($mail, $mailbox, $status, [...$reasons, ...array_map(fn ($n) => trim($n, '[]'), $notes)], null, $this->truncate(trim($mail->text), 20000));

        foreach ($files as $file) {
            $path = Storage::disk(Attachment::DISK)->putFile("inbound/{$email->id}", $file);
            if ($path === false) {
                continue;
            }
            $row = new InboundEmailAttachment;
            $row->inbound_email_id = $email->id;
            $row->path = $path;
            $row->original_name = mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 150);
            $row->mime_type = (string) $file->getMimeType();
            $row->size = (int) $file->getSize();
            $row->save();
        }

        return $email;
    }

    /**
     * @param  array<string, mixed>  $mailbox
     * @param  list<string>  $reasons
     */
    private function record(IncomingEmail $mail, array $mailbox, string $status, array $reasons, ?int $ticketId = null, ?string $body = null): InboundEmail
    {
        $email = new InboundEmail;
        $email->mailbox = (string) $mailbox['key'];
        $email->message_id = $mail->messageId;
        $email->in_reply_to = $mail->inReplyTo;
        $email->from_email = $mail->fromEmail;
        $email->from_name = $mail->fromName === null ? null : mb_substr($mail->fromName, 0, 190);
        $email->to_addresses = $mail->recipients;
        $email->subject = mb_substr($mail->subject, 0, 255);
        $email->body = $body;
        $email->auth_ok = EmailAuthentication::passes($mail->authResults);
        $email->status = $status;
        $email->reasons = $reasons;
        $email->received_at = Carbon::instance($mail->receivedAt);
        $email->ticket_id = $ticketId;
        $email->save();

        return $email;
    }

    // ------------------------------------------------------------------ búsquedas

    private function findCustomer(string $email): ?User
    {
        $user = User::query()->whereRaw('lower(email) = ?', [strtolower($email)])
            ->whereIn('role', [UserRole::CompanyUser->value, UserRole::CompanyAdmin->value])->first();

        // Una cuenta de baja (o de empresa de baja) NO es un cliente conocido a efectos de crear tickets (R17: no se distingue por fuera).
        return $user !== null && $user->isOperational() ? $user : null;
    }

    private function companyByDomain(string $domain): ?Company
    {
        $row = CompanyDomain::query()->where('domain', $domain)->first();
        $company = $row?->company;

        return $company !== null && $company->isActive() ? $company : null;
    }

    private function authReason(IncomingEmail $mail): string
    {
        return $mail->authResults === null || trim($mail->authResults) === ''
            ? 'el servidor no aportó comprobación SPF/DKIM'
            : 'no superó SPF/DKIM';
    }

    private function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).' […]' : $text;
    }
}

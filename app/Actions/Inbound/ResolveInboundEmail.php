<?php

/*
 * Decisiones del administrador sobre un correo en cuarentena: marcar como spam, quitar la marca y convertirlo en ticket.
 * Cuándo tocarla: al cambiar lo que se puede hacer con un correo pendiente.
 *
 * Trampas:
 *  - Crear el ticket desde aquí reutiliza CreateTicket con StaffCreation (el administrador crea y asigna, no atiende, R10) y con EmailOrigin (guarda el
 *    Message-ID: las respuestas del cliente entrarán en el hilo). Canal «Correo».
 *  - Los adjuntos guardados en cuarentena se COPIAN a los del ticket (mismo disco privado, R3) y después se borran de la cuarentena; si falla algo, no se pierde nada.
 *  - Una vez es ticket, el cuerpo del correo se vacía: el texto vive en el ticket. Quedan solo los datos mínimos (retention:run los purga por plazo).
 *  - Cada decisión queda en la auditoría (Audit::record).
 */

namespace App\Actions\Inbound;

use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\EmailOrigin;
use App\Actions\Tickets\StaffCreation;
use App\Enums\TicketChannel;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\InboundEmail;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ResolveInboundEmail
{
    public function __construct(private readonly CreateTicket $createTicket) {}

    public function markSpam(User $actor, InboundEmail $email): void
    {
        Gate::forUser($actor)->authorize('decide', $email);
        $this->decide($actor, $email, InboundEmail::SPAM);
        Audit::record('inbound_spam', "Correo «{$email->subject}» de {$email->from_email} marcado como spam.", $actor);
    }

    /** «No es spam»: vuelve a pendientes (con los 90 días de plazo) para que se decida qué hacer. */
    public function markNotSpam(User $actor, InboundEmail $email): void
    {
        Gate::forUser($actor)->authorize('decide', $email);
        $this->decide($actor, $email, InboundEmail::PENDING);
        Audit::record('inbound_not_spam', "Correo «{$email->subject}» de {$email->from_email}: no es spam, vuelve a pendientes.", $actor);
    }

    /**
     * Crea el ticket a nombre de `$customer` a partir del correo en cuarentena. El departamento sale del BUZÓN por el que llegó (R13).
     * La validación (R6: categoría del departamento, producto de la empresa) la hace el FormRequest; esta acción recomprueba lo esencial.
     */
    public function createTicket(User $actor, InboundEmail $email, User $customer, Category $category, ?Product $product, User $assignee, bool $notifyCustomer): Ticket
    {
        Gate::forUser($actor)->authorize('decide', $email);

        $department = $email->department();
        if ($department === null || $category->department !== $department) {
            throw ValidationException::withMessages(['category_id' => 'El tipo de incidencia no es del departamento de este buzón.']);
        }

        // Los adjuntos de la cuarentena, como archivos temporales para pasar por StoreAttachments como cualquier adjunto.
        $temp = [];
        $files = [];
        foreach ($email->attachments as $attachment) {
            $path = tempnam(sys_get_temp_dir(), 'lmq');
            if ($path === false || ! Storage::disk(Attachment::DISK)->exists($attachment->path)) {
                continue;
            }
            file_put_contents($path, Storage::disk(Attachment::DISK)->get($attachment->path));
            $temp[] = $path;
            $files[] = new UploadedFile($path, $attachment->original_name, null, null, true);
        }

        try {
            $ticket = ($this->createTicket)(
                $customer, $department, $category, $product,
                $this->subject($email), trim((string) $email->body) === '' ? '(correo sin texto)' : trim((string) $email->body), $files, null,
                new StaffCreation($actor, TicketChannel::Email, $assignee, $notifyCustomer),
                new EmailOrigin($email->message_id),
            );
        } finally {
            foreach ($temp as $path) {
                @unlink($path);
            }
        }

        DB::transaction(function () use ($actor, $email, $ticket): void {
            $paths = $email->attachments->pluck('path')->all();
            $email->attachments()->delete();
            $email->forceFill(['status' => InboundEmail::TICKET, 'ticket_id' => $ticket->id, 'body' => null, 'decided_by' => $actor->id, 'decided_at' => now()])->save();
            Storage::disk(Attachment::DISK)->delete($paths);
        });
        Audit::record('inbound_ticket', "Correo «{$email->subject}» de {$email->from_email} convertido en el ticket #{$ticket->number}.", $actor, $customer);

        return $ticket;
    }

    private function decide(User $actor, InboundEmail $email, string $status): void
    {
        $email->forceFill(['status' => $status, 'decided_by' => $actor->id, 'decided_at' => now()])->save();
    }

    private function subject(InboundEmail $email): string
    {
        $subject = trim((string) preg_replace('/^((re|rv|fw|fwd)\s*:\s*)+/i', '', trim($email->subject)));

        return $subject === '' ? '(sin asunto)' : mb_substr($subject, 0, 200);
    }
}

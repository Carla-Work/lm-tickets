{{--
    Correo con la respuesta del equipo (mockup correo-saliente-a-respuesta-y-rebote.html). Variables: las propiedades públicas de TicketReplyMail.
    Cuándo tocarlo: al cambiar el aspecto. Todo se escapa con {{ }}: el texto lo escribe una persona. No añadir notas internas ni datos internos (R2:
    canal, prioridad, etiquetas, quién creó el ticket): el cliente no los ve.
--}}
<!DOCTYPE html>
<html lang="es">
<body style="font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:#040f24;line-height:1.5">
    <p>Hola {{ $recipientName }},</p>
    <div>{!! nl2br(e($text)) !!}</div>
    <p>Un saludo,<br>{{ $senderName }}<br>Ling Marco &amp; Asociados</p>
    @if (count($files) > 0)
        <p style="color:#4a5568;font-size:.9rem">Adjuntos: {{ collect($files)->pluck('name')->implode(', ') }}</p>
    @endif
    @if ($quote)
        <blockquote style="border-left:4px solid #d5d5d5;margin:1rem 0;padding:.2rem .9rem;color:#4a5568">
            <strong>{{ $quote['author'] }}</strong> escribió el {{ $quote['when'] }}:<br>{!! nl2br(e($quote['text'])) !!}
        </blockquote>
    @endif
    <hr style="border:0;border-top:1px solid #ececec;margin:1.5rem 0 .8rem">
    <p style="color:#4a5568;font-size:.8rem">Ticket #{{ $ticketNumber }} · Responde a este correo para continuar la conversación. Para cualquier otra cosa, escribe a {{ $fromAddress }}.</p>
</body>
</html>

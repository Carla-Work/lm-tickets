{{--
    Rebote «el ticket ya está cerrado» (mockup correo-saliente-a-respuesta-y-rebote.html). Variables: propiedades públicas de ClosedTicketBounceMail.
    Cuándo tocarlo: al cambiar el texto. No lleva contenido de ningún mensaje del ticket.
--}}
<!DOCTYPE html>
<html lang="es">
<body style="font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:#040f24;line-height:1.5">
    <p>Hola {{ $recipientName }}:</p>
    <p>El ticket <strong>#{{ $ticketNumber }}</strong> ya está cerrado y no se puede reabrir, así que tu último mensaje no se ha añadido.</p>
    <p>Si el problema sigue o es uno nuevo, abre un ticket nuevo escribiendo a {{ $fromAddress }} (sin responder a este correo) o desde tu panel: «Abrir un ticket nuevo relacionado».</p>
    <p>Gracias,<br>Ling Marco &amp; Asociados</p>
    <hr style="border:0;border-top:1px solid #ececec;margin:1.5rem 0 .8rem">
    <p style="color:#4a5568;font-size:.8rem">Este aviso se envía una sola vez al día por ticket. No contiene datos de otros tickets.</p>
</body>
</html>

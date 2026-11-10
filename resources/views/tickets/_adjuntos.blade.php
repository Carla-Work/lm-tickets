{{--
    Lista de adjuntos de un mensaje. Variable: $attachments (ya filtrados por Attachment::visibleTo en el controlador).
    Cuándo tocarlo: al cambiar cómo se enseñan los adjuntos.
    Trampas (R3): el enlace va a la RUTA de descarga que autoriza (attachments.download), nunca a un fichero ni a
    storage/. El nombre original lo escribió el usuario: se escapa con {{ }} como cualquier texto.
--}}
@if ($attachments->isNotEmpty())
    <ul class="adjuntos">
        @foreach ($attachments as $attachment)
            <li><a href="{{ route('attachments.download', $attachment) }}">📎 {{ $attachment->original_name }} <small>· {{ $attachment->humanSize() }}</small></a></li>
        @endforeach
    </ul>
@endif

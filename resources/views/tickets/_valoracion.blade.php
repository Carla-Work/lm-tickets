{{--
    Valoración de la atención ya hecha (P1, mockups ticket-detalle-cliente-d e interno-g). Variables: $rating (con author), $mine
    (la hizo quien mira). Cuándo tocarla: al cambiar el aspecto.
    Trampa: el comentario es texto libre del cliente: SIEMPRE con {{ }} (escapado), nunca {!! !!}.
--}}
<section class="tarjeta responder valoracion-hecha" aria-label="Valoración de la atención">
    <h2>@if ($mine ?? false)Gracias por tu valoración @else Valoración de la atención @endif</h2>
    <p style="margin:.2rem 0"><span class="est" aria-label="{{ $rating->score }} de 5">{{ \App\Models\TicketRating::stars($rating->score) }}</span> <strong>{{ $rating->score }} de 5 · {{ \App\Models\TicketRating::LABELS[$rating->score] }}</strong></p>
    @if ($rating->comment)<blockquote>{{ $rating->comment }}</blockquote>@endif
    <p class="ayuda" style="margin-top:.6rem">@if (! ($mine ?? false) && $rating->author)De {{ $rating->author->name }}, @endif{{ ($mine ?? false) ? 'Valorado' : 'valorado' }} el {{ $rating->created_at?->timezone(config('app.timezone'))->translatedFormat('j M, H:i') }}. No se puede cambiar.</p>
</section>

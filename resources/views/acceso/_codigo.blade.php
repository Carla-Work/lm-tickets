{{-- Campo del código de 6 dígitos (o de recuperación). Parámetros: $etiqueta, $ayuda (opcional), $recuperacion (opcional). --}}
<div class="campo">
    <label for="codigo">{{ $etiqueta }} <span class="oblig" aria-hidden="true">*</span></label>
    @if (!empty($recuperacion))
        <input type="text" id="codigo" name="codigo" class="codigo-2fa" autocomplete="off" maxlength="9" required placeholder="XXXX-XXXX" autofocus>
    @else
        <input type="text" id="codigo" name="codigo" class="codigo-2fa" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required placeholder="123 456" autofocus>
    @endif
    @isset($ayuda)
        <p class="ayuda">{{ $ayuda }}</p>
    @endisset
</div>

{{--
    Botón «ojo» para ver u ocultar una contraseña. Parámetro: $para (id del campo). Va DENTRO de un `<div class="pw">`
    justo después del `<input type="password">`. Diseño: el de acceso-a-entrar.html (validado), ya usado en el acceso.
    Trampa: nace con `hidden` y solo lo muestra public/js/acceso.js: sin JavaScript no aparece y el formulario
    funciona igual (el contenido debe poder usarse sin JS). No quitar `type="button"`: sin él enviaría el formulario.
--}}
<button type="button" class="ver-pw" data-para="{{ $para }}" aria-label="Mostrar contraseña" hidden>
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
</button>

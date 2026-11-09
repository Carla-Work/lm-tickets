/*
 * Ver u ocultar la contraseña. Mejora progresiva: sin JavaScript el botón (con atributo `hidden`)
 * no aparece y el formulario funciona exactamente igual. Es el único JavaScript de la pantalla de acceso.
 */
document.querySelectorAll('.ver-pw').forEach(function (boton) {
    boton.hidden = false;
    boton.addEventListener('click', function () {
        var campo = document.getElementById(boton.dataset.para);
        var oculto = campo.type === 'password';
        campo.type = oculto ? 'text' : 'password';
        boton.setAttribute('aria-label', oculto ? 'Ocultar contraseña' : 'Mostrar contraseña');
    });
});

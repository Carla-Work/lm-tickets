<?php

/*
 * Mensajes de acceso en español. Cuándo tocarlo: al cambiar un texto de acceso.
 * R17: `failed` es el ÚNICO mensaje para cualquier fallo de entrada (contraseña mala, correo
 * inexistente, cuenta desactivada, invitación sin aceptar, empresa de baja). No distinguirlos nunca.
 */

return [
    'failed' => 'El correo o la contraseña no son correctos. Revisa los datos e inténtalo de nuevo.',
    'password' => 'La contraseña no es correcta.',
    // R20: bloqueo tras 5 intentos fallidos. Texto por definir con la usuaria (el mockup no lo dibujó).
    'throttle' => 'Demasiados intentos fallidos. Vuelve a intentarlo dentro de :minutes minutos.',
];

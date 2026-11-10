# Recuperar el acceso del administrador

Estado: implementado y probado el 2026-11-10 (`tests/Feature/Console/RecoverAdminAccessTest.php`).
Origen: encuadre, sección «Roles» (riesgo de tener un único administrador, R9) y R21.

## Cuándo usarlo

Solo hay **un** administrador. Si pierde la contraseña y el correo de «he olvidado mi contraseña» no le
llega, o pierde el móvil del segundo factor y también los códigos de recuperación, nadie puede crear
cuentas ni gestionar empresas. Esta es la salida de último recurso y **exige acceso al servidor**
(quien puede ejecutarlo ya controla la base de datos).

Antes de usarlo, prueba lo normal: «¿Has olvidado tu contraseña?» en la pantalla de acceso, y los
códigos de recuperación del 2FA.

## Cómo

En el servidor, dentro de la carpeta de la aplicación:

```bash
php artisan admin:recover                    # el administrador en activo; pide confirmación
php artisan admin:recover --disable-2fa      # además borra el segundo factor (clave y códigos)
php artisan admin:recover --reactivate --email=correo@lingmarco.es   # si la cuenta estuviera de baja
php artisan admin:recover --force            # sin preguntar (para un guion)
```

El comando **no fija ni enseña ninguna contraseña**: imprime un enlace de un solo uso (el mismo que
envía la recuperación normal, caduca en 60 minutos). Se lo das al administrador, que abre el enlace y
elige su contraseña. Si tenía 2FA y has usado `--disable-2fa`, entrará sin código y podrá volver a
activarlo (cuando exista esa pantalla en «Mis datos»).

## Qué garantiza

- Solo actúa sobre una cuenta con rol **administrador**; no sirve para ascender a nadie (para eso está
  el traspaso, R9) ni para recuperar agentes o clientes.
- Sin `--disable-2fa` no toca el segundo factor; sin `--reactivate` no quita una baja.
- Si no hay un único administrador en activo, pide el correo con `--email` en vez de adivinar.
- Deja una fila en la auditoría (`Administración › Auditoría`, hecho «Acceso del administrador
  recuperado desde el servidor», sin actor) con lo que se hizo. **El enlace no se guarda.**

## Cuidado

- El enlace sale por la consola: puede quedar en el historial del terminal o en un registro. Es de un
  solo uso y caduca, pero úsalo enseguida.
- Si el correo de la cuenta ya no es suyo, el enlace llega igualmente a quien lo tenga: entrégalo solo
  a la persona correcta.
- No sustituye al plan de copias de seguridad: ver `scripts/db-backup.sh`.

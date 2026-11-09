# Plan de conservación de datos — BORRADOR

Estado: **provisional**. La usuaria fija **2 años** por ahora (2026-11-09) y aplaza el plan
completo. Este documento reúne las opciones para decidir más adelante. No es asesoramiento
legal: los plazos definitivos deben validarse con la asesoría legal antes de producción.

## Qué datos hay y qué pide decidir cada uno

| Dato | Provisional | Opciones a decidir |
|---|---|---|
| Tickets cerrados (asunto, hilo, notas internas) | 2 años desde el cierre | Borrar · anonimizar (conservar para estadística sin datos personales) |
| Adjuntos | 2 años desde el cierre del ticket | Borrar antes que el ticket (suelen ser lo más sensible y ocupan más) |
| Usuarios de empresa dados de baja | 2 años desde la baja | Anonimizar para conservar el historial del ticket sin identificar a la persona |
| Empresa cliente tras terminar la relación | 2 años | Depende de obligaciones contables/contractuales ❓ |
| Registro de actividad (cambios de estado, accesos) | 2 años | Puede necesitar menos o más según su uso |
| Copias de seguridad | ❓ | Las copias contienen datos ya "borrados": su rotación fija el plazo real |

## Decisiones abiertas

1. ¿Se cuenta desde el **cierre del ticket** o desde la **última actividad**? (Propuesta: cierre.)
2. ¿Borrar o anonimizar al vencer el plazo?
3. ¿Aviso previo al cliente antes de borrar?
4. ¿Hay obligaciones legales o contractuales que obliguen a conservar más (por ejemplo, si un ticket respalda una factura o un servicio)?
5. Atención de derechos RGPD (acceso, rectificación, supresión): quién la atiende, en qué plazo y cómo se registra.
6. Rotación de copias de seguridad para que el borrado también llegue a ellas.

## Cómo se implementaría (cuando se decida)

Un comando programado que aplica los plazos, con un registro de lo borrado o anonimizado
(sin datos personales) y un test por cada regla de plazo. Los plazos viven en configuración,
no en el código, para poder cambiarlos sin tocar la lógica.

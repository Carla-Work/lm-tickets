<?php

/*
 * Una fila de la auditoría (sección «Registros»). Cuándo tocarlo: al añadir un hecho nuevo (EVENTS).
 * Trampa: SOLO SE AÑADE, como TicketEvent (R5). No hay código que la edite ni la borre.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** Hechos que se registran, con su nombre para el filtro. La clave es estable: no renombrar. */
    public const EVENTS = [
        'login' => 'Entrada',
        'login_failed' => 'Intento de acceso fallido',
        'login_locked' => 'Acceso bloqueado',
        'logout' => 'Salida',
        'invited' => 'Invitación enviada',
        'activated' => 'Cuenta activada',
        'password_reset' => 'Contraseña cambiada',
        'two_factor_enabled' => 'Segundo factor activado',
        'recovery_code_used' => 'Código de recuperación usado',
        'backup_requested' => 'Copia de seguridad pedida',
        'admin_recovered' => 'Acceso del administrador recuperado desde el servidor',
        'company_created' => 'Empresa creada',
        'company_updated' => 'Datos de empresa cambiados',
        'company_products_changed' => 'Productos de empresa cambiados',
        'company_deactivated' => 'Empresa dada de baja',
        'company_reactivated' => 'Empresa reactivada',
        'product_created' => 'Producto creado',
        'product_updated' => 'Producto editado',
        'product_status_changed' => 'Producto activado o desactivado',
        'category_created' => 'Categoría creada',
        'category_updated' => 'Categoría editada',
        'category_status_changed' => 'Categoría activada o desactivada',
        'user_deactivated' => 'Cuenta dada de baja',
        'user_reactivated' => 'Cuenta reactivada',
        'team_changed' => 'Equipo de un agente cambiado',
        'admin_transferred' => 'Rol de administrador traspasado',
        'company_role_changed' => 'Rol de un usuario de empresa cambiado',
        'password_changed' => 'Contraseña cambiada desde «Mis datos»',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['properties' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_id');
    }
}

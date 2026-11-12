<?php

/*
 * Empresa cliente: agrupa a sus usuarios, sus productos contratados y sus tickets.
 * Cuándo tocarlo: al añadir datos de la empresa. R1: lo que ve un cliente se limita por empresa.
 *
 * Trampa: no se borra; la baja es `deactivated_at`. Los tickets se conservan según el plan de
 * conservación de datos.
 */

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'tax_id', 'phone', 'contact_email'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    // Larastan lee el cuerpo de este método gracias a `parseModelCastsMethod: true` en phpstan.neon.
    protected function casts(): array
    {
        return ['deactivated_at' => 'datetime'];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Dominios de correo de la empresa (pista para el correo entrante; ver CompanyDomain). @return HasMany<CompanyDomain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(CompanyDomain::class);
    }

    /** Productos contratados. Solo los asigna el administrador (R7). @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withTimestamps();
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }
}

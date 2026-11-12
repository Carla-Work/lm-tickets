<?php

/*
 * Dominio de correo de una empresa cliente (p. ej. «hermanossantana.es»). Cuándo tocarlo: al cambiar cómo se reconocen las empresas por correo.
 *
 * Trampas:
 *  - SOLO es una PISTA: un correo de un dominio registrado cuyo remitente no tiene cuenta va a cuarentena con la empresa sugerida; nunca crea
 *    un ticket (R18: las cuentas nacen por invitación) ni da acceso a nada.
 *  - Los dominios de correo gratuito (gmail, hotmail…) no se pueden registrar (config/inbound.php → free_domains): lo comprueba CompanyDomains::normalize.
 *  - Se guarda normalizado (minúsculas, sin «@»); único: un dominio es de UNA empresa.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['domain'])]
class CompanyDomain extends Model
{
    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}

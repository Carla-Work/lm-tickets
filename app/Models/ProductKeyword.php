<?php

/*
 * Palabra clave de un producto (p. ej. «fichaje» → oneCOP): sirve para asignar el producto a un ticket que llega por correo.
 * Cuándo tocarlo: al cambiar cómo se guardan. La lógica de coincidencia está en App\Support\Inbound\KeywordMatcher.
 *
 * Trampa: se guarda NORMALIZADA (minúsculas, sin tildes: KeywordMatcher::normalize) y es ÚNICA en toda la tabla: cada palabra es de UN solo producto.
 * Quitarla la borra de verdad (no es dato de clientes); el producto, en cambio, nunca se borra (R19).
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['keyword'])]
class ProductKeyword extends Model
{
    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

<?php

/*
 * Cambiar los datos de una empresa y los productos que tiene contratados (R7: solo el administrador).
 * Cuándo tocarlo: al cambiar qué datos de empresa se editan o cómo se asignan productos.
 *
 * Trampas:
 *  - Quitar un producto a una empresa NO toca los tickets que ya lo llevan: el producto del ticket vive en
 *    `tickets.product_id`, no en la tabla pivote (R19: los tickets antiguos conservan su producto).
 *  - Solo se pueden AÑADIR productos activos. Uno desactivado que la empresa ya tenía se puede conservar o quitar.
 */

namespace App\Actions\Companies;

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UpdateCompany
{
    /** @param  array{name: string, tax_id: string, phone?: ?string, contact_email?: ?string}  $data */
    public function details(User $actor, Company $company, array $data): void
    {
        Gate::forUser($actor)->authorize('update', $company);

        $company->fill($data);
        if ($company->isDirty()) {
            $company->save();
            Audit::record('company_updated', "Datos de «{$company->name}» cambiados.", $actor, null, null, ['company_id' => $company->id]);
        }
    }

    /** @param  list<int>  $productIds */
    public function products(User $actor, Company $company, array $productIds): void
    {
        Gate::forUser($actor)->authorize('assignProducts', $company);

        DB::transaction(function () use ($actor, $company, $productIds): void {
            $current = $company->products()->pluck('products.id')->all();
            $allowedNew = Product::query()->where('active', true)->whereIn('id', $productIds)->pluck('id')->all();
            // Se conserva lo que ya tenía y se marcó, y se añade solo lo activo.
            $final = array_values(array_unique(array_merge(array_intersect($productIds, $current), $allowedNew)));

            $changes = $company->products()->sync($final);
            if ($changes['attached'] !== [] || $changes['detached'] !== []) {
                Audit::record('company_products_changed', "Productos de «{$company->name}» cambiados.", $actor, null, null, [
                    'company_id' => $company->id, 'added' => $changes['attached'], 'removed' => $changes['detached'],
                ]);
            }
        });
    }
}

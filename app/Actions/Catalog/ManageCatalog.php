<?php

/*
 * Altas, ediciones y activación/desactivación de productos y categorías del catálogo (solo el administrador).
 * Cuándo tocarlo: al cambiar qué se puede editar del catálogo. Pantalla: Administración › Productos y categorías.
 *
 * Trampas:
 *  - NO existe borrado (R19): un producto o categoría se DESACTIVA. Desactivado deja de ofrecerse al abrir un
 *    ticket (TicketController/StoreTicketRequest ya filtran por `active`), pero los tickets y empresas que lo
 *    tenían lo conservan.
 *  - La categoría no cambia de departamento una vez creada: los tickets que la usan quedarían incoherentes (R6).
 *  - Cada método autoriza con la policy aunque el controlador ya lo haga (dos capas, como en los tickets).
 */

namespace App\Actions\Catalog;

use App\Enums\Department;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Gate;

class ManageCatalog
{
    public function createProduct(User $actor, string $name, ?string $description): Product
    {
        Gate::forUser($actor)->authorize('create', Product::class);

        $product = new Product;
        $product->fill(['name' => $name, 'description' => $description, 'active' => true])->save();
        Audit::record('product_created', "Producto «{$product->name}» creado.", $actor, null, null, ['product_id' => $product->id]);

        return $product;
    }

    public function updateProduct(User $actor, Product $product, string $name, ?string $description): void
    {
        Gate::forUser($actor)->authorize('update', $product);

        $product->fill(['name' => $name, 'description' => $description]);
        if ($product->isDirty()) {
            $product->save();
            Audit::record('product_updated', "Producto «{$product->name}» editado.", $actor, null, null, ['product_id' => $product->id]);
        }
    }

    public function setProductActive(User $actor, Product $product, bool $active): void
    {
        Gate::forUser($actor)->authorize('update', $product);

        if ($product->active !== $active) {
            $product->forceFill(['active' => $active])->save();
            Audit::record('product_status_changed', 'Producto «'.$product->name.'» '.($active ? 'activado' : 'desactivado').'.', $actor, null, null, ['product_id' => $product->id, 'active' => $active]);
        }
    }

    public function createCategory(User $actor, Department $department, string $name): Category
    {
        Gate::forUser($actor)->authorize('create', Category::class);

        $category = new Category;
        $category->fill(['name' => $name, 'active' => true]);
        $category->department = $department; // fuera de fillable a propósito: solo lo fija el servidor
        $category->save();
        Audit::record('category_created', "Categoría «{$category->name}» creada en {$department->label()}.", $actor, null, null, ['category_id' => $category->id]);

        return $category;
    }

    public function updateCategory(User $actor, Category $category, string $name): void
    {
        Gate::forUser($actor)->authorize('update', $category);

        $category->fill(['name' => $name]);
        if ($category->isDirty()) {
            $category->save();
            Audit::record('category_updated', "Categoría «{$category->name}» editada.", $actor, null, null, ['category_id' => $category->id]);
        }
    }

    public function setCategoryActive(User $actor, Category $category, bool $active): void
    {
        Gate::forUser($actor)->authorize('update', $category);

        if ($category->active !== $active) {
            $category->forceFill(['active' => $active])->save();
            Audit::record('category_status_changed', 'Categoría «'.$category->name.'» '.($active ? 'activada' : 'desactivada').'.', $actor, null, null, ['category_id' => $category->id, 'active' => $active]);
        }
    }
}

<?php

/*
 * «Productos y categorías» (Administración, solo el administrador): el catálogo que el cliente ve al abrir un ticket.
 * Cuándo tocarlo: al cambiar estas pantallas. Mockup validado: admin-catalogo-a-productos-categorias.html.
 *
 * Trampas:
 *  - Nada se borra (R19): se desactiva. No hay rutas DELETE y no deben añadirse.
 *  - El mockup dibuja «Editar» pero no la pantalla de edición: se resolvió con una página mínima por elemento
 *    (misma tarjeta y campos que el alta). Pendiente de que la usuaria la confirme.
 *  - Las categorías se agrupan por los cuatro departamentos fijos; «Otros» también se muestra (aquí el
 *    administrador es quien lo ve todo).
 */

namespace App\Http\Controllers\Admin;

use App\Actions\Catalog\ManageCatalog;
use App\Enums\Department;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryRequest;
use App\Http\Requests\Admin\ProductRequest;
use App\Models\Category;
use App\Models\IncidentWord;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CatalogController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Product::class);
        Gate::authorize('viewAny', Category::class);

        return view('admin.catalog.index', [
            'user' => $request->user(),
            'products' => Product::query()->withCount('companies')->with('keywords')->orderByDesc('active')->orderBy('name')->get(),
            'incidentWords' => IncidentWord::query()->orderBy('word')->get(),
            'categories' => Category::query()->orderByDesc('active')->orderBy('name')->get()->groupBy(fn (Category $c) => $c->department->value),
        ]);
    }

    public function storeProduct(ProductRequest $request, ManageCatalog $catalog): RedirectResponse
    {
        Gate::authorize('create', Product::class);

        $product = $catalog->createProduct($this->actor($request), $request->string('name')->toString(), $request->input('description') ?: null);

        return redirect()->route('admin.catalog.index')->with('status', "Producto «{$product->name}» añadido al catálogo.");
    }

    public function editProduct(Request $request, Product $product): View
    {
        Gate::authorize('update', $product);

        return view('admin.catalog.edit', ['user' => $request->user(), 'kind' => 'product', 'item' => $product]);
    }

    public function updateProduct(ProductRequest $request, Product $product, ManageCatalog $catalog): RedirectResponse
    {
        Gate::authorize('update', $product);

        $catalog->updateProduct($this->actor($request), $product, $request->string('name')->toString(), $request->input('description') ?: null);

        return redirect()->route('admin.catalog.index')->with('status', 'Producto guardado.');
    }

    public function setProductStatus(Request $request, Product $product, ManageCatalog $catalog): RedirectResponse
    {
        Gate::authorize('update', $product);

        $catalog->setProductActive($this->actor($request), $product, $request->boolean('activo'));

        return redirect()->route('admin.catalog.index')->with('status', "Producto «{$product->name}» ".($product->active ? 'activado' : 'desactivado').'.');
    }

    public function storeCategory(CategoryRequest $request, ManageCatalog $catalog): RedirectResponse
    {
        Gate::authorize('create', Category::class);

        $department = Department::from($request->string('department')->toString());
        $category = $catalog->createCategory($this->actor($request), $department, $request->string('name')->toString());

        return redirect()->route('admin.catalog.index')->with('status', "Categoría «{$category->name}» añadida a {$department->label()}.");
    }

    public function editCategory(Request $request, Category $category): View
    {
        Gate::authorize('update', $category);

        return view('admin.catalog.edit', ['user' => $request->user(), 'kind' => 'category', 'item' => $category]);
    }

    public function updateCategory(CategoryRequest $request, Category $category, ManageCatalog $catalog): RedirectResponse
    {
        Gate::authorize('update', $category);

        $catalog->updateCategory($this->actor($request), $category, $request->string('name')->toString());

        return redirect()->route('admin.catalog.index')->with('status', 'Categoría guardada.');
    }

    public function setCategoryStatus(Request $request, Category $category, ManageCatalog $catalog): RedirectResponse
    {
        Gate::authorize('update', $category);

        $catalog->setCategoryActive($this->actor($request), $category, $request->boolean('activo'));

        return redirect()->route('admin.catalog.index')->with('status', "Categoría «{$category->name}» ".($category->active ? 'activada' : 'desactivada').'.');
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        return $user;
    }
}

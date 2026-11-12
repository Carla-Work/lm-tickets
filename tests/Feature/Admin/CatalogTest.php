<?php

/*
 * Administración › Productos y categorías (R6, R7, R19): altas, ediciones y desactivación; nada se borra.
 * Cuándo tocarla: al cambiar quién edita el catálogo o qué implica desactivar; cambiar también la matriz de
 * docs/plan/autorizacion.md. Si falla, se arregla el código o se discute la regla: NO se adapta el test.
 */

namespace Tests\Feature\Admin;

use App\Enums\Department;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
    }

    #[TestDox('Solo el administrador abre y usa el catálogo: todos los demás roles reciben 403 en todas las rutas')]
    public function test_only_admin_uses_catalog(): void
    {
        $producto = Product::factory()->create();
        $categoria = Category::factory()->forDepartment(Department::Support)->create();
        $pantallas = [
            ['get', route('admin.catalog.index')], ['post', route('admin.catalog.products.store')],
            ['get', route('admin.catalog.products.edit', $producto)], ['post', route('admin.catalog.products.update', $producto)],
            ['post', route('admin.catalog.products.status', $producto)], ['post', route('admin.catalog.categories.store')],
            ['get', route('admin.catalog.categories.edit', $categoria)], ['post', route('admin.catalog.categories.update', $categoria)],
            ['post', route('admin.catalog.categories.status', $categoria)],
        ];

        foreach ($this->a as $clave => $actor) {
            if ($clave === 'admin') {
                continue;
            }
            foreach ($pantallas as [$metodo, $url]) {
                $this->actingAs($actor)->{$metodo}($url, ['name' => '', 'department' => 'inventado'] /* inválido a propósito: quien no puede recibe 403, no mensajes de validación */)->assertForbidden();
            }
        }
        $this->assertSame(1, Product::count());
        // Las categorías «por defecto» del correo entrante (una por departamento, creadas por la migración) no cuentan: solo la que creó este test.
        $this->assertSame(1, Category::where('is_default', false)->count());

        foreach ($pantallas as [$metodo, $url]) {
            $this->app['auth']->forgetGuards();
            $this->{$metodo}($url)->assertRedirect(route('login'));
        }
        $this->actingAs($this->a['admin'])->get(route('admin.catalog.index'))->assertOk();
    }

    #[TestDox('R19: no existe ninguna ruta de borrado del catálogo; solo se desactiva')]
    public function test_there_is_no_delete_route(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            if (str_starts_with($ruta->uri(), 'administracion/catalogo')) {
                $this->assertNotContains('DELETE', $ruta->methods(), "la ruta {$ruta->uri()} permite DELETE");
            }
        }
    }

    #[TestDox('Se crea, se edita y se desactiva un producto; el nombre no se repite y todo queda auditado')]
    public function test_product_lifecycle(): void
    {
        $admin = $this->actingAs($this->a['admin']);
        $admin->post(route('admin.catalog.products.store'), ['name' => 'oneNUEVO', 'description' => 'Algo'])->assertRedirect(route('admin.catalog.index'));
        $producto = Product::where('name', 'oneNUEVO')->firstOrFail();
        $this->assertTrue($producto->active);

        $admin->post(route('admin.catalog.products.store'), ['name' => 'oneNUEVO'])->assertSessionHasErrors('name');
        $otro = Product::factory()->create(['name' => 'oneOTRO']);
        $admin->post(route('admin.catalog.products.update', $producto), ['name' => 'oneOTRO'])->assertSessionHasErrors('name');
        $admin->post(route('admin.catalog.products.update', $producto), ['name' => 'oneNUEVO', 'description' => 'Cambiada'])->assertRedirect();
        $this->assertSame('Cambiada', $producto->fresh()->description);

        $this->empresaA->products()->attach($producto);
        $admin->post(route('admin.catalog.products.status', $producto), ['activo' => 0])->assertRedirect();
        $this->assertFalse($producto->fresh()->active);
        $this->assertSame(1, $this->empresaA->products()->count(), 'desactivar no quita el producto a la empresa que ya lo tenía (R19)');
        $admin->post(route('admin.catalog.products.status', $producto), ['activo' => 1])->assertRedirect();
        $this->assertTrue($producto->fresh()->active);

        foreach (['product_created', 'product_updated', 'product_status_changed'] as $evento) {
            $this->assertTrue(AuditLog::where('event', $evento)->exists(), "falta auditar {$evento}");
        }
        $this->assertNotNull($otro->fresh());
    }

    #[TestDox('Una categoría nace en su departamento, no se repite dentro de él (sí en otro), y su departamento no se puede cambiar al editar')]
    public function test_category_rules(): void
    {
        $admin = $this->actingAs($this->a['admin']);
        $admin->post(route('admin.catalog.categories.store'), ['department' => 'support', 'name' => 'Impresoras'])->assertRedirect();
        $categoria = Category::where('name', 'Impresoras')->firstOrFail();
        $this->assertSame(Department::Support, $categoria->department);

        $admin->post(route('admin.catalog.categories.store'), ['department' => 'support', 'name' => 'Impresoras'])->assertSessionHasErrors('name');
        $admin->post(route('admin.catalog.categories.store'), ['department' => 'websites', 'name' => 'Impresoras'])->assertRedirect();
        $admin->post(route('admin.catalog.categories.store'), ['department' => 'inventado', 'name' => 'X'])->assertSessionHasErrors('department');

        $admin->post(route('admin.catalog.categories.update', $categoria), ['name' => 'Impresoras y red', 'department' => 'other'])->assertSessionHasErrors('department');
        $admin->post(route('admin.catalog.categories.update', $categoria), ['name' => 'Impresoras y red'])->assertRedirect();
        $this->assertSame('Impresoras y red', $categoria->fresh()->name);
        $this->assertSame(Department::Support, $categoria->fresh()->department);
    }

    #[TestDox('Desactivar una categoría o un producto lo retira del formulario de nuevo ticket, pero el ticket antiguo lo conserva (R6, R19)')]
    public function test_deactivated_items_leave_the_new_ticket_form(): void
    {
        $categoria = Category::factory()->forDepartment(Department::Support)->create(['name' => 'Categoría retirable']);
        $producto = Product::factory()->create(['name' => 'oneRETIRABLE']);
        $this->empresaA->products()->attach($producto);
        $ticket = $this->ticket(Department::Support);
        $ticket->forceFill(['category_id' => $categoria->id, 'product_id' => $producto->id])->save();

        $this->actingAs($this->a['cuA'])->get(route('tickets.create'))
            ->assertSee('<option value="'.$categoria->id.'"', false)->assertSee('<option value="'.$producto->id.'"', false);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->a['admin'])->post(route('admin.catalog.categories.status', $categoria), ['activo' => 0])->assertRedirect(route('admin.catalog.index'));
        $this->assertFalse($categoria->fresh()->active);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->a['admin'])->post(route('admin.catalog.products.status', $producto), ['activo' => 0])->assertRedirect(route('admin.catalog.index'));
        $this->assertFalse($producto->fresh()->active);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->a['cuA'])->get(route('tickets.create'))
            // Se busca la OPCIÓN del desplegable, no el nombre: el aviso «desactivado» de la sesión también lo lleva.
            ->assertDontSee('<option value="'.$categoria->id.'"', false)->assertDontSee('<option value="'.$producto->id.'"', false);
        $this->assertSame($categoria->id, $ticket->fresh()->category_id);
        $this->assertSame($producto->id, $ticket->fresh()->product_id);
        $this->assertTrue(AuditLog::where('event', 'category_status_changed')->exists());
    }

    #[TestDox('La pantalla del catálogo lista los productos con su número de empresas y las categorías por departamento')]
    public function test_index_content(): void
    {
        $producto = Product::factory()->create(['name' => 'oneLISTADO']);
        $this->empresaA->products()->attach($producto);
        Category::factory()->forDepartment(Department::Websites)->create(['name' => 'Cat listada']);

        $this->actingAs($this->a['admin'])->get(route('admin.catalog.index'))->assertOk()
            ->assertSee('oneLISTADO')->assertSee('Cat listada')->assertSee('Añadir al catálogo');
    }
}

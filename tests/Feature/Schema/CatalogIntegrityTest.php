<?php

/*
 * Integridad del catálogo (productos y categorías) y de las claves foráneas.
 * Cuándo tocarlo: si cambia cómo se asignan productos o se guardan categorías.
 * Reglas: R19 (un producto en uso no se borra) y que nada con tickets se borre.
 */

namespace Tests\Feature\Schema;

use App\Enums\Department;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Ticket;
use Database\Seeders\CatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class CatalogIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('Un producto asignado a una empresa no se puede borrar (R19)')]
    public function test_assigned_product_cannot_be_deleted(): void
    {
        $empresa = Company::factory()->create();
        $producto = Product::factory()->create();
        $empresa->products()->attach($producto);

        $this->expectException(QueryException::class);
        $producto->delete();
    }

    #[TestDox('Un producto usado en un ticket no se puede borrar (R19)')]
    public function test_product_used_in_a_ticket_cannot_be_deleted(): void
    {
        $producto = Product::factory()->create();
        Ticket::factory()->create(['product_id' => $producto->id]);

        $this->expectException(QueryException::class);
        $producto->delete();
    }

    #[TestDox('Un producto que nadie usa sí se puede borrar a nivel de base de datos')]
    public function test_unused_product_can_be_deleted(): void
    {
        $producto = Product::factory()->create();
        $producto->delete();

        $this->assertDatabaseMissing('products', ['id' => $producto->id]);
    }

    #[TestDox('Una empresa no puede tener dos veces el mismo producto')]
    public function test_company_cannot_have_same_product_twice(): void
    {
        $empresa = Company::factory()->create();
        $producto = Product::factory()->create();
        $empresa->products()->attach($producto);

        $this->expectException(QueryException::class);
        $empresa->products()->attach($producto);
    }

    #[TestDox('Dos productos no pueden llamarse igual')]
    public function test_product_names_are_unique(): void
    {
        Product::factory()->create(['name' => 'oneCOP']);

        $this->expectException(QueryException::class);
        Product::factory()->create(['name' => 'oneCOP']);
    }

    #[TestDox('Dos empresas no pueden tener el mismo CIF')]
    public function test_company_tax_id_is_unique(): void
    {
        Company::factory()->create(['tax_id' => 'B12345678']);

        $this->expectException(QueryException::class);
        Company::factory()->create(['tax_id' => 'B12345678']);
    }

    #[TestDox('Una categoría no se repite dentro de un departamento, pero sí entre departamentos')]
    public function test_category_unique_per_department(): void
    {
        Category::factory()->forDepartment(Department::Support)->create(['name' => 'Otra consulta']);
        Category::factory()->forDepartment(Department::Other)->create(['name' => 'Otra consulta']); // otro departamento: permitido

        $this->expectException(QueryException::class);
        Category::factory()->forDepartment(Department::Support)->create(['name' => 'Otra consulta']);
    }

    #[TestDox('Una empresa con tickets no se puede borrar')]
    public function test_company_with_tickets_cannot_be_deleted(): void
    {
        $ticket = Ticket::factory()->create();

        $this->expectException(QueryException::class);
        $ticket->company->delete();
    }

    #[TestDox('El catálogo inicial crea los 10 productos y las 16 categorías acordados')]
    public function test_catalog_seeder_creates_agreed_catalog(): void
    {
        $this->seed(CatalogSeeder::class);

        $this->assertSame(10, Product::count());
        $this->assertSame(16, Category::count());
        $this->assertSame(5, Category::where('department', 'support')->count());
        $this->assertSame(4, Category::where('department', 'web_apps')->count());
        $this->assertSame(3, Category::where('department', 'websites')->count());
        $this->assertSame(4, Category::where('department', 'other')->count());
    }

    #[TestDox('El catálogo inicial es idempotente: ejecutarlo dos veces no duplica nada')]
    public function test_catalog_seeder_is_idempotent(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->seed(CatalogSeeder::class);

        $this->assertSame(10, Product::count());
        $this->assertSame(16, Category::count());
    }
}

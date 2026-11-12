<?php

/*
 * Catálogo inicial: productos y categorías acordados (encuadre y mockups validados).
 * Cuándo tocarlo: nunca para cambiar el catálogo en producción (eso lo hace el administrador desde
 * la aplicación); solo si cambia el catálogo INICIAL de una instalación nueva.
 *
 * Es idempotente: se puede ejecutar varias veces sin duplicar nada. OJO: volver a ejecutarlo en una instalación en marcha devuelve las palabras clave
 * iniciales que el administrador haya quitado (solo es para instalaciones nuevas).
 *
 * Correo entrante (P1): también deja la categoría «Sin clasificar» de cada departamento, las palabras de incidencia iniciales y, por cada producto, su
 * propio nombre como palabra clave (más «fichaje» → oneCOP, el ejemplo de la usuaria). El resto lo añade el administrador desde la pantalla.
 */

namespace Database\Seeders;

use App\Enums\Department;
use App\Models\Category;
use App\Models\IncidentWord;
use App\Models\Product;
use App\Models\ProductKeyword;
use App\Support\Inbound\KeywordMatcher;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    /** @var array<string, string> nombre => descripción */
    private const PRODUCTS = [
        'oneCOP' => 'Gestión de comunidades de propietarios',
        'oneFact' => 'Facturación',
        'oneCAR' => 'Gestión de flota',
        'oneSIGW' => 'Gestión documental y firma',
        'oneHADA' => 'Gestión de personal',
        'Pool de mantenimiento' => 'Bolsa de horas de soporte',
        'Asistencia informática' => 'Asistencia puntual',
        'Backup de datos' => 'Copias de seguridad',
        'Mantenimiento web' => 'Mantenimiento de la página web',
        'Servicios de seguridad' => 'Antivirus, firewall y redes',
    ];

    public function run(): void
    {
        foreach (self::PRODUCTS as $nombre => $descripcion) {
            Product::updateOrCreate(['name' => $nombre], ['description' => $descripcion, 'active' => true]);
        }

        foreach ($this->categories() as $departamento => $nombres) {
            foreach ($nombres as $nombre) {
                Category::updateOrCreate(['department' => $departamento, 'name' => $nombre], ['active' => true]);
            }
            // Categoría de los tickets que llegan por correo (R6 la exige); el agente la corrige después.
            $porDefecto = Category::query()->firstOrNew(['department' => $departamento, 'name' => (string) config('inbound.default_category')]);
            $porDefecto->forceFill(['active' => true, 'is_default' => true])->save();
        }

        foreach (self::INCIDENT_WORDS as $word) {
            IncidentWord::firstOrCreate(['word' => KeywordMatcher::normalize($word)]);
        }
        foreach (Product::query()->get() as $product) {
            $this->keyword($product, $product->name);
        }
        $onecop = Product::query()->where('name', 'oneCOP')->first();
        if ($onecop !== null) {
            $this->keyword($onecop, 'fichaje');
        }
    }

    /** `product_id` no es asignable en masa a propósito: se fija con forceFill. Cada palabra es de UN solo producto (única en la tabla). */
    private function keyword(Product $product, string $word): void
    {
        $keyword = KeywordMatcher::normalize($word);
        if (! ProductKeyword::query()->where('keyword', $keyword)->exists()) {
            (new ProductKeyword)->forceFill(['product_id' => $product->id, 'keyword' => $keyword])->save();
        }
    }

    /** Palabras de incidencia iniciales (las del mockup admin-catalogo-c-palabras-clave). @var list<string> */
    private const INCIDENT_WORDS = ['error', 'no funciona', 'fallo', 'no puedo', 'urgente', 'se ha caido', 'no carga', 'bloqueado'];

    /** @return array<string, list<string>> departamento => categorías (las del mockup validado) */
    private function categories(): array
    {
        return [
            Department::Support->value => ['Equipos y sistemas', 'Correo electrónico', 'Copias de seguridad', 'Seguridad', 'Red e impresoras'],
            Department::WebApps->value => ['Error en la aplicación', 'Consulta de uso', 'Mejora o cambio solicitado', 'Acceso y usuarios'],
            Department::Websites->value => ['Cambio de contenido', 'Web caída o con errores', 'Dominio y hosting'],
            Department::Other->value => ['Presupuesto', 'Factura o cobro', 'Contrato o pool de horas', 'Otra consulta'],
        ];
    }
}

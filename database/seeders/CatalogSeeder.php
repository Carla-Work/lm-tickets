<?php

/*
 * Catálogo inicial: productos y categorías acordados (encuadre y mockups validados).
 * Cuándo tocarlo: nunca para cambiar el catálogo en producción (eso lo hace el administrador desde
 * la aplicación); solo si cambia el catálogo INICIAL de una instalación nueva.
 *
 * Es idempotente: se puede ejecutar varias veces sin duplicar nada.
 */

namespace Database\Seeders;

use App\Enums\Department;
use App\Models\Category;
use App\Models\Product;
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
        }
    }

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

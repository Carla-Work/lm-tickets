<?php

/*
 * Accesibilidad del marcado: errores de formulario ligados a su campo y enlace «Saltar al contenido».
 * Cuándo tocarla: al cambiar los layouts (`layouts/panel`, `layouts/acceso`) o la directiva `@errorAttrs`
 * (AppServiceProvider). Trampas que vigila:
 *  - un campo con error lleva aria-invalid y aria-describedby apuntando a un <p id="err-CAMPO"> que EXISTE
 *    (si no, un lector de pantalla no sabe qué campo falló); sin error, esos atributos no aparecen;
 *  - cada `@errorAttrs('x')` de una vista tiene su `id="err-x"` en la misma vista, y viceversa;
 *  - el primer enlace del <body> salta al <main id="contenido">, que tiene que existir.
 * Si falla, se arregla el código o se discute la regla: NO se adapta el test.
 */

namespace Tests\Feature\Accessibility;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class AccessibilityTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
    }

    #[TestDox('Un campo con error lleva aria-invalid y aria-describedby apuntando al mensaje, que existe')]
    public function test_field_with_error_is_linked_to_its_message(): void
    {
        $respuesta = $this->actingAs($this->a['cuA'])
            ->from(route('tickets.create'))
            ->post(route('tickets.store'), ['subject' => '', 'description' => '', 'department' => 'support'])
            ->assertRedirect(route('tickets.create'));

        $html = $this->followRedirects($respuesta)->getContent();

        foreach (['subject', 'description'] as $campo) {
            $this->assertMatchesRegularExpression('/name="'.$campo.'"[^>]*aria-invalid="true"[^>]*aria-describedby="err-'.$campo.'"/', $html, "«{$campo}» sin enlazar con su error");
            $this->assertStringContainsString('id="err-'.$campo.'"', $html, "falta el mensaje err-{$campo}");
        }
    }

    #[TestDox('Sin errores, los campos no llevan aria-invalid ni aria-describedby')]
    public function test_clean_form_has_no_error_attributes(): void
    {
        $this->actingAs($this->a['cuA'])->get(route('tickets.create'))->assertOk()
            ->assertDontSee('aria-invalid', false)->assertDontSee('aria-describedby', false);
    }

    #[TestDox('Cada @errorAttrs de una vista tiene su mensaje id="err-…" en la misma vista')]
    public function test_every_linked_field_has_its_message_in_the_same_view(): void
    {
        $vistas = File::allFiles(resource_path('views'));
        $enlazados = 0;

        foreach ($vistas as $vista) {
            $fuente = $vista->getContents();
            preg_match_all("/@errorAttrs\('([a-z_]+)'\)/", $fuente, $campos);
            foreach (array_unique($campos[1]) as $campo) {
                $enlazados++;
                $this->assertSame(1, substr_count($fuente, 'id="err-'.$campo.'"'), $vista->getRelativePathname()." — «{$campo}» necesita UN id=\"err-{$campo}\"");
            }
        }

        // Si esta cuenta baja a 0 es que alguien quitó la directiva de las vistas, no que no haya nada que vigilar.
        $this->assertGreaterThan(20, $enlazados);
    }

    #[TestDox('El panel y la pantalla de acceso empiezan con «Saltar al contenido» y su destino existe')]
    public function test_skip_link_points_to_an_existing_main(): void
    {
        $paginas = [
            $this->actingAs($this->a['cuA'])->get(route('tickets.index'))->getContent(),
            $this->actingAs($this->a['agSup'])->get(route('inbox'))->getContent(),
        ];
        $this->app['auth']->forgetGuards();
        $paginas[] = $this->get(route('login'))->getContent();

        foreach ($paginas as $html) {
            $this->assertStringContainsString('<a class="saltar" href="#contenido">', $html);
            $this->assertMatchesRegularExpression('/<main[^>]*id="contenido"/', $html);
            // Tiene que ser lo primero alcanzable: antes de la barra lateral y de cualquier otro enlace del cuerpo.
            $this->assertSame(1, preg_match('/<body[^>]*>\s*<a class="saltar"/', $html), 'el salto debe ser lo primero del <body>');
        }
    }
}

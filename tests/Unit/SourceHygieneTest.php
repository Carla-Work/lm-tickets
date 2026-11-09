<?php

/*
 * Guardia del código fuente: detecta errores de escritura que PHP no avisa al compilar.
 * Cuándo tocarlo: al añadir otra comprobación del mismo tipo.
 *
 * Qué detecta y por qué existe: en PHP, los bytes no ASCII (como «, » o una tilde) cuentan como letras
 * de un nombre de variable. Por eso, dentro de unas comillas dobles, "«$nombre»" se lee como la variable
 * `$nombre»` (que no existe) y falla en tiempo de ejecución con «Undefined variable». Este error se
 * cometió DOS veces; ahora lo caza un test. Arreglo: escribir "«{$nombre}»".
 *
 * Usa el tokenizador de PHP, no una búsqueda de texto: así no da falsos positivos con las comillas
 * simples, donde no hay interpolación.
 */

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class SourceHygieneTest extends TestCase
{
    /** @return list<string> */
    private function phpFiles(): array
    {
        $raiz = dirname(__DIR__, 2);
        $ficheros = [];
        foreach (['app', 'tests', 'database', 'routes', 'config', 'bootstrap', 'lang'] as $carpeta) {
            $ruta = $raiz.'/'.$carpeta;
            if (! is_dir($ruta)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ruta, RecursiveDirectoryIterator::SKIP_DOTS)) as $fichero) {
                if ($fichero->getExtension() === 'php' && ! str_contains($fichero->getPathname(), '/cache/')) {
                    $ficheros[] = $fichero->getPathname();
                }
            }
        }

        return $ficheros;
    }

    #[TestDox('Ninguna variable de PHP lleva un carácter no ASCII en su nombre (p. ej. «$nombre» con comillas dobles)')]
    public function test_no_variable_name_contains_non_ascii_characters(): void
    {
        $fallos = [];
        foreach ($this->phpFiles() as $fichero) {
            foreach (token_get_all((string) file_get_contents($fichero)) as $token) {
                if (is_array($token) && $token[0] === T_VARIABLE && preg_match('/[^\x00-\x7F]/', $token[1])) {
                    $fallos[] = str_replace(dirname(__DIR__, 2).'/', '', $fichero).':'.$token[2].' → '.$token[1].'  (¿falta {$…} alrededor de la variable?)';
                }
            }
        }

        $this->assertSame([], $fallos, "Variables con caracteres no ASCII:\n".implode("\n", $fallos));
    }

    #[TestDox('El guardia funciona: reconoce el error en un trozo de código con el fallo')]
    public function test_guard_detects_the_bug_it_is_meant_to_catch(): void
    {
        $codigo = '<?php $x = "«$nombre»";';
        $detectada = false;
        foreach (token_get_all($codigo) as $token) {
            if (is_array($token) && $token[0] === T_VARIABLE && preg_match('/[^\x00-\x7F]/', $token[1])) {
                $detectada = true;
            }
        }

        $this->assertTrue($detectada, 'el tokenizador debería ver $nombre» como una variable');
    }
}

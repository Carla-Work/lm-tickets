<?php

/*
 * Prueba de App\Support\RecoveryCodes: generación, hash y uso único de los códigos de recuperación.
 * Cuándo tocarlo: si cambia el formato de los códigos.
 */

namespace Tests\Feature\Auth;

use App\Support\RecoveryCodes;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class RecoveryCodesTest extends TestCase
{
    #[TestDox('Se generan 8 códigos distintos con formato XXXX-XXXX y sin caracteres confusos (0 O 1 I L)')]
    public function test_generate_format(): void
    {
        $codigos = RecoveryCodes::generate(8);

        $this->assertCount(8, $codigos);
        $this->assertCount(8, array_unique($codigos));
        foreach ($codigos as $codigo) {
            $this->assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}$/', $codigo);
        }
    }

    #[TestDox('Dos tandas de códigos nunca coinciden')]
    public function test_batches_differ(): void
    {
        $this->assertNotSame(RecoveryCodes::generate(8), RecoveryCodes::generate(8));
    }

    #[TestDox('La normalización quita guiones y espacios y pasa a mayúsculas')]
    public function test_normalize(): void
    {
        $this->assertSame('ABCD1234', RecoveryCodes::normalize(' abcd-12 34 '));
    }

    #[TestDox('Los hashes no contienen el código en claro')]
    public function test_hashes_do_not_contain_plain_codes(): void
    {
        $codigos = RecoveryCodes::generate(3);
        $hashes = RecoveryCodes::hashAll($codigos);

        $this->assertCount(3, $hashes);
        foreach ($codigos as $i => $codigo) {
            $this->assertStringNotContainsString(str_replace('-', '', $codigo), $hashes[$i]);
        }
    }

    #[TestDox('Consumir un código válido lo quita de la lista y deja los demás')]
    public function test_consume_removes_only_the_used_code(): void
    {
        $codigos = RecoveryCodes::generate(4);
        $hashes = RecoveryCodes::hashAll($codigos);

        $restantes = RecoveryCodes::consume($hashes, $codigos[2]);

        $this->assertCount(3, $restantes);
        $this->assertNull(RecoveryCodes::consume($restantes, $codigos[2]), 'el mismo código no vale dos veces');
        $this->assertNotNull(RecoveryCodes::consume($restantes, $codigos[0]), 'los demás siguen valiendo');
    }

    #[TestDox('Un código que no está en la lista, vacío o solo con guiones se rechaza')]
    public function test_consume_rejects_unknown_and_empty(): void
    {
        $hashes = RecoveryCodes::hashAll(RecoveryCodes::generate(3));

        $this->assertNull(RecoveryCodes::consume($hashes, 'ZZZZ-ZZZZ'));
        $this->assertNull(RecoveryCodes::consume($hashes, ''));
        $this->assertNull(RecoveryCodes::consume($hashes, '----'));
    }
}

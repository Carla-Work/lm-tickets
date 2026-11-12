<?php

/*
 * Palabras clave y comprobación SPF/DKIM (P1, correo entrante). Cuándo tocarlo: al cambiar cómo se compara o qué cuenta como autenticado.
 * Vigila: normalización (tildes, mayúsculas), palabra COMPLETA, y el criterio «SPF o DKIM en pass y DMARC no en fail».
 */

namespace Tests\Feature\Inbound;

use App\Models\IncidentWord;
use App\Models\Product;
use App\Models\ProductKeyword;
use App\Support\Inbound\EmailAuthentication;
use App\Support\Inbound\KeywordMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class KeywordAndAuthTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('Normaliza igual al guardar y al buscar: minúsculas, sin tildes, espacios simples')]
    public function test_normalize(): void
    {
        $this->assertSame('se ha caido el sistema', KeywordMatcher::normalize("  Se HA   caído\nel Sistema "));
    }

    #[TestDox('Busca palabra COMPLETA: «factura» no salta en «facturación» ni «car» en «carta», pero sí con tildes o mayúsculas distintas')]
    public function test_whole_word_match(): void
    {
        $texto = KeywordMatcher::normalize('Problema con la FACTURACIÓN y la carta. Fichaje roto');

        $this->assertFalse(KeywordMatcher::contains($texto, 'factura'));
        $this->assertFalse(KeywordMatcher::contains($texto, 'car'));
        $this->assertTrue(KeywordMatcher::contains($texto, 'facturacion'));
        $this->assertTrue(KeywordMatcher::contains($texto, 'fichaje'));
        $this->assertFalse(KeywordMatcher::contains($texto, ''));
    }

    #[TestDox('Encuentra los productos por sus palabras y las palabras de incidencia')]
    public function test_it_finds_products_and_incident_words(): void
    {
        $producto = Product::factory()->create(['name' => 'oneCOP']);
        (new ProductKeyword)->forceFill(['product_id' => $producto->id, 'keyword' => 'fichaje'])->save();
        IncidentWord::create(['word' => 'no funciona']);
        $matcher = new KeywordMatcher;

        $this->assertSame([$producto->id => 'fichaje'], $matcher->matches('Error de Fichaje'));
        $this->assertSame([], $matcher->matches('Nada que ver'));
        $this->assertTrue($matcher->hasIncidentWord('Esto NO FUNCIONA nada'));
        $this->assertFalse($matcher->hasIncidentWord('Todo bien'));
    }

    #[TestDox('SPF o DKIM en pass lo autentican; DMARC en fail, una cabecera vacía o sin pass, no; y solo con la comprobación desactivada pasa cualquier cosa')]
    public function test_authentication_criteria(): void
    {
        config(['inbound.require_auth' => true]);
        $this->assertTrue(EmailAuthentication::passes('mx; spf=pass'));
        $this->assertTrue(EmailAuthentication::passes('mx; dkim=pass header.d=x.es'));
        $this->assertTrue(EmailAuthentication::passes('mx; SPF=PASS; dmarc=pass'));
        $this->assertFalse(EmailAuthentication::passes('mx; spf=pass; dmarc=fail'));
        $this->assertFalse(EmailAuthentication::passes('mx; spf=fail; dkim=none'));
        $this->assertFalse(EmailAuthentication::passes('mx; spf=softfail'));
        $this->assertFalse(EmailAuthentication::passes(''));
        $this->assertFalse(EmailAuthentication::passes(null));

        config(['inbound.require_auth' => false]);
        $this->assertTrue(EmailAuthentication::passes(null));
    }
}

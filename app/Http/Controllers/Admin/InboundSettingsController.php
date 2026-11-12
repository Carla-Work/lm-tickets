<?php

/*
 * Palabras clave de producto, palabras de incidencia y dominios de empresa (ajustes del correo entrante; solo el administrador, P1).
 * Cuándo tocarlo: al cambiar esos ajustes. Mockup validado: admin-catalogo-c-palabras-clave.html (los dominios usan el estilo ya validado de «Empresa»).
 *
 * Trampas:
 *  - Las palabras se guardan NORMALIZADAS (KeywordMatcher::normalize) y cada una es de UN producto; mínimo 3 caracteres (una palabra de 1-2 letras saltaría en cualquier correo).
 *  - Quitar una palabra o un dominio los BORRA (no son datos de clientes; el producto y la empresa no se tocan). Por eso las rutas son POST «borrar», no DELETE.
 *  - Un dominio de correo gratuito (gmail…) o el del propio equipo NO se puede registrar: «tener gmail» no es pertenecer a una empresa.
 *  - Todo esto solo da PISTAS al clasificar: nada crea cuentas ni tickets por sí solo (R18).
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyDomain;
use App\Models\InboundEmail;
use App\Models\IncidentWord;
use App\Models\Product;
use App\Models\ProductKeyword;
use App\Support\Audit;
use App\Support\Inbound\KeywordMatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InboundSettingsController extends Controller
{
    private const MIN = 3;

    public function storeKeyword(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('manageSettings', InboundEmail::class);
        $word = $this->word($request, 'palabra', 'product_keywords', 'keyword');

        $product->keywords()->create(['keyword' => $word]);
        Audit::record('keyword_added', "Palabra clave «{$word}» añadida a {$product->name}.", $request->user());

        return redirect()->route('admin.catalog.index')->with('status', "Palabra «{$word}» añadida a {$product->name}.");
    }

    public function destroyKeyword(Request $request, ProductKeyword $keyword): RedirectResponse
    {
        Gate::authorize('manageSettings', InboundEmail::class);
        $keyword->delete();
        Audit::record('keyword_removed', "Palabra clave «{$keyword->keyword}» quitada.", $request->user());

        return redirect()->route('admin.catalog.index')->with('status', "Palabra «{$keyword->keyword}» quitada.");
    }

    public function storeIncidentWord(Request $request): RedirectResponse
    {
        Gate::authorize('manageSettings', InboundEmail::class);
        $word = $this->word($request, 'palabra', 'incident_words', 'word');

        IncidentWord::create(['word' => $word]);

        return redirect()->route('admin.catalog.index')->with('status', "Palabra de incidencia «{$word}» añadida.");
    }

    public function destroyIncidentWord(Request $request, IncidentWord $word): RedirectResponse
    {
        Gate::authorize('manageSettings', InboundEmail::class);
        $word->delete();

        return redirect()->route('admin.catalog.index')->with('status', "Palabra de incidencia «{$word->word}» quitada.");
    }

    public function storeDomain(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('manageSettings', InboundEmail::class);

        $domain = strtolower(trim((string) $request->input('dominio')));
        $domain = (string) preg_replace('/^(?:@|www\.)+/', '', $domain);
        if (! preg_match('/^(?=.{4,190}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
            throw ValidationException::withMessages(['dominio' => 'Escribe un dominio válido, por ejemplo «hermanossantana.es».']);
        }
        if (in_array($domain, (array) config('inbound.free_domains'), true) || $domain === strtolower((string) config('inbound.internal_domain'))) {
            throw ValidationException::withMessages(['dominio' => 'Ese es un dominio de correo gratuito o del propio equipo: no puede ser de una empresa cliente.']);
        }
        if (CompanyDomain::query()->where('domain', $domain)->exists()) {
            throw ValidationException::withMessages(['dominio' => 'Ese dominio ya está registrado.']);
        }

        $company->domains()->create(['domain' => $domain]);
        Audit::record('company_domain_added', "Dominio «{$domain}» añadido a {$company->name}.", $request->user());

        return redirect()->route('admin.companies.show', $company)->with('status', "Dominio «{$domain}» añadido.");
    }

    public function destroyDomain(Request $request, Company $company, CompanyDomain $domain): RedirectResponse
    {
        Gate::authorize('manageSettings', InboundEmail::class);
        abort_unless($domain->company_id === $company->id, 404);

        $domain->delete();
        Audit::record('company_domain_removed', "Dominio «{$domain->domain}» quitado de {$company->name}.", $request->user());

        return redirect()->route('admin.companies.show', $company)->with('status', "Dominio «{$domain->domain}» quitado.");
    }

    /** Valida y devuelve la palabra normalizada: 3-100 caracteres y sin repetir en su tabla. */
    private function word(Request $request, string $field, string $table, string $column): string
    {
        $word = KeywordMatcher::normalize((string) $request->input($field));
        if (mb_strlen($word) < self::MIN || mb_strlen($word) > 100) {
            throw ValidationException::withMessages([$field => 'Escribe una palabra o frase de 3 a 100 caracteres.']);
        }
        if (DB::table($table)->where($column, $word)->exists()) {
            throw ValidationException::withMessages([$field => 'Esa palabra ya existe.']);
        }

        return $word;
    }
}

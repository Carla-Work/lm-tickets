<?php

/*
 * Coincidencia de palabras clave en un texto (producto por palabras clave; «¿parece una incidencia?»). Cuándo tocarlo: al cambiar cómo se compara.
 *
 * Trampas:
 *  - Todo se NORMALIZA igual (minúsculas, sin tildes, espacios simples) tanto al guardar la palabra como al buscarla: «Fichaje» = «fichaje» = «FICHAJE».
 *  - Se busca palabra COMPLETA (no «factura» dentro de «facturación» a menos que esté también como palabra): una palabra corta como «car» no debe saltar en «carta».
 *  - Mirar solo asunto y cuerpo; NUNCA se ejecuta nada del texto: es un dato del remitente.
 */

namespace App\Support\Inbound;

use App\Models\IncidentWord;
use App\Models\ProductKeyword;
use Illuminate\Support\Str;

final class KeywordMatcher
{
    public static function normalize(string $text): string
    {
        $text = Str::of($text)->lower()->ascii()->toString();

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function contains(string $normalizedText, string $normalizedWord): bool
    {
        if ($normalizedWord === '') {
            return false;
        }

        return (bool) preg_match('/(?<![a-z0-9])'.preg_quote($normalizedWord, '/').'(?![a-z0-9])/u', $normalizedText);
    }

    /**
     * Productos con alguna palabra clave en el texto: id del producto → la palabra que coincidió.
     *
     * @return array<int, string>
     */
    public function matches(string $text): array
    {
        $normalized = self::normalize($text);
        $found = [];
        foreach (ProductKeyword::query()->orderBy('id')->get(['product_id', 'keyword']) as $keyword) {
            if (! isset($found[$keyword->product_id]) && self::contains($normalized, $keyword->keyword)) {
                $found[$keyword->product_id] = $keyword->keyword;
            }
        }

        return $found;
    }

    /** ¿El texto lleva alguna palabra de incidencia («error», «no funciona»…)? */
    public function hasIncidentWord(string $text): bool
    {
        $normalized = self::normalize($text);

        foreach (IncidentWord::query()->pluck('word') as $word) {
            if (self::contains($normalized, $word)) {
                return true;
            }
        }

        return false;
    }
}

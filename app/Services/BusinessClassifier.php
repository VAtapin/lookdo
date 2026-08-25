<?php

namespace App\Services;

use App\Models\BusinessClassification;
use App\Models\BusinessPhrase;
use Illuminate\Support\Str;

class BusinessClassifier
{
    public function normalize(string $text): string
    {
        $text = Str::lower(Str::ascii(trim($text)));

        return trim((string) preg_replace('/[^a-z0-9]+/u', ' ', $text));
    }

    public function classify(string $text, string $locale = 'ru'): BusinessClassification
    {
        $normalized = $this->normalize($text);
        $phrases = BusinessPhrase::with(['category', 'variation'])->where('enabled', true)->whereIn('locale', [$locale, 'ru', 'de', 'en'])->get();
        $ranked = $phrases->map(function (BusinessPhrase $phrase) use ($normalized) {
            similar_text($normalized, $phrase->normalized_phrase, $percent);
            $exact = $normalized === $phrase->normalized_phrase;
            $contains = str_contains($normalized, $phrase->normalized_phrase) || str_contains($phrase->normalized_phrase, $normalized);
            $score = $exact ? 1.0 : min(.99, (($percent / 100) * .72) + ($contains ? .2 : 0)) * max(.5, min(1.25, $phrase->weight));

            return ['category_id' => $phrase->category_id, 'variation_id' => $phrase->variation_id, 'category' => $phrase->category?->localized('name'), 'variation' => $phrase->variation?->localized('name'), 'template_code' => $phrase->variation?->template_code, 'score' => round(min(1, $score), 4), 'phrase' => $phrase->phrase, 'exact' => $exact];
        })->sortByDesc('score')->unique(fn ($r) => $r['category_id'].':'.($r['variation_id'] ?? 0))->take(3)->values();
        $best = $ranked->first();

        return BusinessClassification::create(['original_text' => $text, 'normalized_text' => $normalized, 'category_id' => $best['category_id'] ?? null, 'variation_id' => $best['variation_id'] ?? null, 'confidence' => $best['score'] ?? 0, 'source' => ($best['exact'] ?? false) ? 'exact' : 'fuzzy', 'candidates' => $ranked->all()]);
    }
}

<?php

namespace App\Services;

use App\Models\BusinessClassification;
use App\Models\BusinessPhrase;
use Illuminate\Support\Str;
use Throwable;

class BusinessClassifier
{
    public function __construct(private OpenAiService $openAi, private OpenAiBudgetService $budget) {}

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
        $source = ($best['exact'] ?? false) ? 'exact' : 'fuzzy';
        $ai = null;

        if ($this->openAi->configured() && $ranked->isNotEmpty() && ($best['score'] ?? 0) < .88) {
            try {
                $this->budget->ensureAvailable();
                $allowed = $ranked->map(fn (array $item, int $index) => [
                    'choice' => $index + 1,
                    'category' => $item['category'],
                    'variation' => $item['variation'],
                    'example_phrase' => $item['phrase'],
                ])->values();
                $ai = $this->openAi->text(
                    'Choose exactly one best matching business type from the supplied choices. Never invent a category. Return JSON only: {"choice": number, "confidence": number between 0 and 1}.',
                    json_encode(['locale' => $locale, 'business_description' => $text, 'choices' => $allowed], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                );
                $decision = json_decode($ai['text'], true, flags: JSON_THROW_ON_ERROR);
                $index = (int) ($decision['choice'] ?? 0) - 1;
                if ($ranked->has($index)) {
                    $best = $ranked->get($index);
                    $best['score'] = max(0, min(1, (float) ($decision['confidence'] ?? $best['score'])));
                    $source = 'ai';
                }
            } catch (Throwable $exception) {
                report($exception);
                $ai = null;
            }
        }

        $classification = BusinessClassification::create(['original_text' => $text, 'normalized_text' => $normalized, 'category_id' => $best['category_id'] ?? null, 'variation_id' => $best['variation_id'] ?? null, 'confidence' => $best['score'] ?? 0, 'source' => $source, 'ai_model' => $source === 'ai' ? $ai['model'] : null, 'candidates' => $ranked->all()]);
        if ($source === 'ai') {
            $this->budget->record('business_classification', $ai['model'], $ai['input_tokens'], $ai['output_tokens'], classificationId: $classification->id);
        }

        return $classification;
    }
}

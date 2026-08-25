<?php

namespace App\Services;

use App\Models\BusinessClassification;
use App\Models\BusinessPhrase;
use App\Models\BusinessVariation;
use App\Models\RequestTemplate;
use App\Models\SystemSetting;
use Illuminate\Support\Str;
use Throwable;

class BusinessClassifier
{
    public function __construct(private OpenAiService $openAi, private OpenAiBudgetService $budget) {}

    public function normalize(string $text): string
    {
        $text = str_replace('ё', 'е', Str::lower(trim($text)));

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
    }

    public function classify(string $text, string $locale = 'ru'): BusinessClassification
    {
        $normalized = $this->normalize($text);
        $phrases = BusinessPhrase::with(['category', 'variation'])->where('enabled', true)->whereIn('locale', [$locale, 'ru', 'de', 'en'])->get();
        $ranked = $phrases->map(function (BusinessPhrase $phrase) use ($normalized) {
            similar_text($normalized, $phrase->normalized_phrase, $percent);
            $exact = $normalized === $phrase->normalized_phrase;
            $contains = $phrase->normalized_phrase !== '' && (str_contains($normalized, $phrase->normalized_phrase) || str_contains($phrase->normalized_phrase, $normalized));
            $inputTokens = array_filter(explode(' ', $normalized));
            $phraseTokens = array_filter(explode(' ', $phrase->normalized_phrase));
            $overlap = $phraseTokens === [] ? 0 : count(array_intersect($inputTokens, $phraseTokens)) / count($phraseTokens);
            $score = match (true) {
                $exact => 1.0,
                $contains => .92,
                $overlap > 0 => min(.86, .3 + ($overlap * .4) + (($percent / 100) * .16)),
                $percent >= 68 => ($percent / 100) * .68,
                default => 0,
            };
            $score *= max(.5, min(1.25, $phrase->weight));

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
                $fallback = $this->defaultCandidate();
                $allowed->push(['choice' => $allowed->count() + 1, 'category' => $fallback['category'], 'variation' => $fallback['variation'], 'example_phrase' => null]);
                $ai = $this->openAi->text(
                    'Choose exactly one best matching business type from the supplied choices. Never invent a category. The last universal choice must be used when no specific choice clearly matches. Return JSON only: {"choice": number, "confidence": number between 0 and 1}.',
                    json_encode(['locale' => $locale, 'business_description' => $text, 'choices' => $allowed], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                );
                $decision = json_decode($ai['text'], true, flags: JSON_THROW_ON_ERROR);
                $index = (int) ($decision['choice'] ?? 0) - 1;
                if ($ranked->has($index) && (float) ($decision['confidence'] ?? 0) >= .45) {
                    $best = $ranked->get($index);
                    $best['score'] = max(0, min(1, (float) ($decision['confidence'] ?? $best['score'])));
                    $source = 'ai';
                } else {
                    $best = $fallback;
                    $ranked = collect([$fallback]);
                    $source = 'fallback';
                    $ai = null;
                }
            } catch (Throwable $exception) {
                report($exception);
                $ai = null;
            }
        }

        if (($best['score'] ?? 0) < .48) {
            $best = $this->defaultCandidate();
            $ranked = collect([$best]);
            $source = 'fallback';
            $ai = null;
        }

        $classification = BusinessClassification::create(['original_text' => $text, 'normalized_text' => $normalized, 'category_id' => $best['category_id'] ?? null, 'variation_id' => $best['variation_id'] ?? null, 'confidence' => $best['score'] ?? 0, 'source' => $source, 'ai_model' => $source === 'ai' ? $ai['model'] : null, 'candidates' => $ranked->all()]);
        if ($source === 'ai') {
            $this->budget->record('business_classification', $ai['model'], $ai['input_tokens'], $ai['output_tokens'], classificationId: $classification->id);
        }

        return $classification;
    }

    public function defaultCandidate(): array
    {
        $code = (string) SystemSetting::read('default_request_template_code', 'general-services.general');
        $template = RequestTemplate::with(['category', 'variation'])
            ->where('enabled', true)
            ->where('code', $code)
            ->first()
            ?? RequestTemplate::with(['category', 'variation'])->where('enabled', true)->whereNotNull('variation_id')->orderBy('sort_order')->firstOrFail();

        return [
            'category_id' => $template->category_id,
            'variation_id' => $template->variation_id,
            'category' => $template->category?->localized('name'),
            'variation' => $template->variation?->localized('name'),
            'template_code' => $template->code,
            'score' => 0,
            'phrase' => null,
            'exact' => false,
            'fallback' => true,
        ];
    }

    public function defaultVariation(): BusinessVariation
    {
        return BusinessVariation::with('category')->findOrFail($this->defaultCandidate()['variation_id']);
    }
}

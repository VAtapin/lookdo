<?php

namespace App\Jobs;

use App\Models\TenantRequest;
use App\Services\BookPurchasePricingService;
use App\Services\OpenAiBudgetService;
use App\Services\OpenAiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AnalyzeTenantRequestMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 240;

    public function __construct(public int $tenantRequestId) {}

    public function handle(OpenAiService $openAi, OpenAiBudgetService $budget, BookPurchasePricingService $pricing): void
    {
        if (! $openAi->configured()) {
            return;
        }

        $request = TenantRequest::with(['tenant.businessProfile.variation', 'template', 'media', 'values'])
            ->find($this->tenantRequestId);
        if (! $request) {
            return;
        }

        $variationCode = (string) data_get($request->contact_snapshot, 'business_variation_code', $request->tenant?->businessProfile?->variation?->code);
        $configuration = $request->template?->resolvedForVariation($variationCode, (array) config('tenant_apps.templates', [])) ?? [];
        if (data_get($configuration, 'condition_assessment.enabled', true) === false) {
            return;
        }

        $images = $request->media->where('type', 'image')->take(6)->map(function ($media): ?array {
            if (! Storage::disk('public')->exists($media->storage_key)) {
                return null;
            }

            return [
                'contents' => Storage::disk('public')->get($media->storage_key),
                'mime' => (string) (data_get($media->metadata, 'mime') ?: Storage::disk('public')->mimeType($media->storage_key) ?: 'image/jpeg'),
            ];
        })->filter()->values()->all();
        if ($images === []) {
            return;
        }

        $locale = $request->locale ?: 'de';
        $context = $this->localized(
            data_get($configuration, 'condition_assessment.context', $request->tenant?->businessProfile?->variation?->name ?? 'the specialist activity'),
            $locale,
        );
        $submitted = $request->values->reject(fn ($value) => $value->field_key === 'ai_condition_assessment')
            ->mapWithKeys(fn ($value) => [$value->field_key => data_get($value->value, 'value')])
            ->filter(fn ($value) => filled($value))->all();
        $existingAssessment = (array) ($request->values->firstWhere('field_key', 'ai_condition_assessment')?->value ?? []);
        $isBookPurchase = $variationCode === 'purchase.books';
        $properties = $isBookPurchase ? [
            'comment' => ['type' => 'string'],
            'condition_grade' => ['type' => 'string', 'enum' => ['poor', 'fair', 'good', 'very_good', 'unknown']],
            'recommended_purchase_price' => ['type' => 'string'],
            'price_basis' => ['type' => 'string'],
        ] : ['comment' => ['type' => 'string']];

        $budget->ensureAvailable();
        $result = $openAi->structuredWithImages(
            $isBookPurchase
                ? 'You assist a professional antiquarian book buyer. Return a practical, moderately detailed buying note in '.$locale.'. Use 3 to 5 compact sentences, maximum 600 characters. Cover visible condition of binding, spine and pages, important strengths or defects, and only the most relevant point that still needs checking. Do not repeat title, author, ISBN, publisher, year, edition, description, page references or catalogue data already displayed elsewhere. Do not mention Google Books, catalogues, technical identification methods, AI, internal recommendations, valuation disclaimers or guarantees. If purchase_price_anchor is present, copy it exactly as recommended_purchase_price and only explain its visible-condition basis. Otherwise recommend a deliberately low defensible purchase price. Never invent authenticity, hidden damage, completeness, rarity or market sales.'
                : 'You are an assistant to a professional handling '.$context.'. Inspect only what is actually visible in the customer photos. Write a short practical condition note of 2 to 4 sentences for the professional in '.$locale.'. Mention visible condition, relevant defects or identifying details, and what important photo or fact is missing. Do not estimate a price, guarantee authenticity, diagnose hidden damage, or invent facts.',
            json_encode(['request' => $request->summary, 'submitted_fields' => $submitted, 'catalog' => $existingAssessment['catalog'] ?? [], 'purchase_price_anchor' => $existingAssessment['recommended_purchase_price'] ?? null, 'photo_slots' => $request->media->where('type', 'image')->pluck('slot_key')->values()->all()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'tenant_media_condition_assessment',
            [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => $properties,
                'required' => array_keys($properties),
            ],
            $images,
        );
        $decoded = json_decode($result['text'], true, flags: JSON_THROW_ON_ERROR);
        if ($isBookPurchase) {
            $conditionGrade = (string) ($existingAssessment['condition_grade'] ?? $decoded['condition_grade'] ?? 'unknown');
            $rawPrice = filled($existingAssessment['recommended_purchase_price'] ?? null)
                ? (string) $existingAssessment['recommended_purchase_price']
                : (string) ($decoded['recommended_purchase_price'] ?? '');
            [$proposedAmount, $currency] = $pricing->parsePrice($rawPrice);
            $stablePrice = $pricing->stabilize(
                $request->tenant,
                (string) ($submitted['isbn'] ?? ''),
                $proposedAmount > 0 ? $proposedAmount : .50,
                $currency,
                $conditionGrade,
            );
            $decoded['recommended_purchase_price'] = $pricing->format($stablePrice['amount'], $stablePrice['currency']);
            $decoded['condition_grade'] = $conditionGrade;
        }
        $comment = $isBookPurchase
            ? $this->bookAssessment($decoded, $locale)
            : Str::limit($this->singleLine((string) ($decoded['comment'] ?? '')), 500);
        if ($comment === '') {
            return;
        }

        $request->values()->updateOrCreate(
            ['field_key' => 'ai_condition_assessment'],
            ['value' => array_merge($existingAssessment, $decoded, ['value' => $comment, 'display_value' => $comment, 'model' => $result['model'], 'analyzed_at' => now()->toIso8601String(), 'status' => 'complete'])],
        );
        if ($isBookPurchase) {
            $pricing->remember(
                $request,
                (string) ($submitted['isbn'] ?? ''),
                (string) $decoded['recommended_purchase_price'],
                (string) ($decoded['condition_grade'] ?? 'unknown'),
                ['analysis' => 'background'],
            );
        }
        $budget->record('tenant_media_condition_assessment', $result['model'], $result['input_tokens'], $result['output_tokens'], tenantId: $request->tenant_id);
    }

    private function localized(mixed $value, string $locale): string
    {
        if (! is_array($value)) {
            return (string) $value;
        }

        return (string) ($value[$locale] ?? $value['de'] ?? $value['en'] ?? $value['ru'] ?? reset($value));
    }

    /** @param array<string, mixed> $assessment */
    private function bookAssessment(array $assessment, string $locale): string
    {
        $comment = Str::limit($this->cleanBookComment((string) ($assessment['comment'] ?? '')), 600);
        $price = Str::limit($this->singleLine((string) ($assessment['recommended_purchase_price'] ?? '')), 40);
        $basis = Str::limit($this->singleLine((string) ($assessment['price_basis'] ?? '')), 140);
        $priceLabel = match ($locale) {
            'ru' => 'Закупка',
            'uk' => 'Закупівля',
            'de' => 'Ankauf',
            default => 'Purchase',
        };

        return trim(implode("\n", array_filter([
            $comment !== '' ? '• '.$comment : null,
            $price !== '' ? '• '.$priceLabel.': '.$price.($basis !== '' ? ' — '.$basis : '') : null,
        ])));
    }

    private function singleLine(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function cleanBookComment(string $value): string
    {
        $value = preg_replace([
            '/(?:Внутренняя рекомендация|Внутрішня рекомендація)[^.?!]*(?:[.?!]|$)/iu',
            '/(?:не оценка и не гарантия|не оцінка і не гарантія)[^.?!]*(?:[.?!]|$)/iu',
            '/[^.?!]*(?:Google Books|каталожн(?:ой|ої) запис)[^.?!]*(?:[.?!]|$)/iu',
            '/(?:Internal recommendation|Not an appraisal or guarantee)[^.?!]*(?:[.?!]|$)/iu',
            '/[^.?!]*Google Books[^.?!]*(?:[.?!]|$)/iu',
        ], ' ', $value) ?? $value;

        return $this->singleLine($value);
    }
}

<?php

namespace App\Jobs;

use App\Models\TenantRequest;
use App\Services\OpenAiBudgetService;
use App\Services\OpenAiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class AnalyzeTenantRequestMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 240;

    public function __construct(public int $tenantRequestId) {}

    public function handle(OpenAiService $openAi, OpenAiBudgetService $budget): void
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
            'description' => ['type' => 'string'],
            'condition' => ['type' => 'string'],
            'recommended_purchase_price' => ['type' => 'string'],
            'price_basis' => ['type' => 'string'],
        ] : ['comment' => ['type' => 'string']];

        $budget->ensureAvailable();
        $result = $openAi->structuredWithImages(
            $isBookPurchase
                ? 'You assist a professional antiquarian book buyer. Using the photos, submitted bibliographic data and catalogue result, write a complete internal description in '.$locale.': identify the edition, summarize visible binding/spine/page condition, defects, completeness and notable features, then recommend a deliberately low defensible purchase price. Explain the price basis briefly and clearly label uncertainty. This is an internal buying recommendation, not an appraisal or guarantee. Never invent an ISBN, edition, market listing, authenticity, hidden damage or source.'
                : 'You are an assistant to a professional handling '.$context.'. Inspect only what is actually visible in the customer photos. Write a short practical condition note of 2 to 4 sentences for the professional in '.$locale.'. Mention visible condition, relevant defects or identifying details, and what important photo or fact is missing. Do not estimate a price, guarantee authenticity, diagnose hidden damage, or invent facts.',
            json_encode(['request' => $request->summary, 'submitted_fields' => $submitted, 'catalog' => $existingAssessment['catalog'] ?? [], 'prefilled_price' => $existingAssessment['recommended_purchase_price'] ?? null, 'photo_slots' => $request->media->where('type', 'image')->pluck('slot_key')->values()->all()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
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
        $comment = $isBookPurchase
            ? trim(implode("\n\n", array_filter([
                (string) ($decoded['description'] ?? ''),
                (string) ($decoded['condition'] ?? ''),
                (string) ($decoded['comment'] ?? ''),
                filled($decoded['recommended_purchase_price'] ?? null) ? 'Empfohlener niedriger Ankaufspreis / Recommended low purchase price: '.trim((string) $decoded['recommended_purchase_price']) : null,
                (string) ($decoded['price_basis'] ?? ''),
            ])))
            : trim((string) ($decoded['comment'] ?? ''));
        if ($comment === '') {
            return;
        }

        $request->values()->updateOrCreate(
            ['field_key' => 'ai_condition_assessment'],
            ['value' => array_merge($existingAssessment, $decoded, ['value' => $comment, 'model' => $result['model'], 'analyzed_at' => now()->toIso8601String(), 'status' => 'complete'])],
        );
        $budget->record('tenant_media_condition_assessment', $result['model'], $result['input_tokens'], $result['output_tokens'], tenantId: $request->tenant_id);
    }

    private function localized(mixed $value, string $locale): string
    {
        if (! is_array($value)) {
            return (string) $value;
        }

        return (string) ($value[$locale] ?? $value['de'] ?? $value['en'] ?? $value['ru'] ?? reset($value));
    }
}

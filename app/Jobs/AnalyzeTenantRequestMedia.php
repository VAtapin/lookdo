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

        $budget->ensureAvailable();
        $result = $openAi->structuredWithImages(
            'You are an assistant to a professional handling '.$context.'. Inspect only what is actually visible in the customer photos. Write a short practical condition note of 2 to 4 sentences for the professional in '.$locale.'. Mention visible condition, relevant defects or identifying details, and what important photo or fact is missing. Do not estimate a price, guarantee authenticity, diagnose hidden damage, or invent facts.',
            json_encode(['request' => $request->summary, 'submitted_fields' => $submitted, 'photo_slots' => $request->media->where('type', 'image')->pluck('slot_key')->values()->all()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'tenant_media_condition_assessment',
            [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => ['comment' => ['type' => 'string']],
                'required' => ['comment'],
            ],
            $images,
        );
        $decoded = json_decode($result['text'], true, flags: JSON_THROW_ON_ERROR);
        $comment = trim((string) ($decoded['comment'] ?? ''));
        if ($comment === '') {
            return;
        }

        $request->values()->updateOrCreate(
            ['field_key' => 'ai_condition_assessment'],
            ['value' => ['value' => $comment, 'model' => $result['model'], 'analyzed_at' => now()->toIso8601String()]],
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

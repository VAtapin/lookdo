<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\OpenAiBudgetService;
use App\Services\OpenAiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Throwable;

class GenerateTenantAppCustomization implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 240;

    public function __construct(public int $tenantId) {}

    public function handle(OpenAiService $openAi, OpenAiBudgetService $budget): void
    {
        $tenant = Tenant::with(['profile', 'businessProfile.category', 'businessProfile.template', 'businessProfile.variation', 'users'])->find($this->tenantId);
        if (! $tenant?->profile || ! $tenant->businessProfile?->template) {
            return;
        }
        if (! $openAi->configured()) {
            $this->setStatus($tenant, 'unavailable');

            return;
        }

        $template = $tenant->businessProfile->template;
        $base = $template->resolvedForVariation(
            $tenant->businessProfile->variation?->code,
            (array) config('tenant_apps.templates', []),
        );
        $ownerId = $tenant->users->first(fn ($user) => $user->pivot?->role === 'owner')?->id;
        $budget->ensureAvailable($ownerId);
        $result = $openAi->structured(
            $this->instructions(),
            json_encode([
                'business_description' => $tenant->business_description,
                'business_name' => $tenant->name,
                'primary_locale' => $tenant->locale,
                'selected_category' => $tenant->businessProfile->category?->name,
                'selected_variation' => $tenant->businessProfile->variation?->name,
                'base_template' => [
                    'code' => $template->code,
                    'engine' => $base['engine'] ?? 'request',
                    'hero' => $base['hero'] ?? [],
                    'media' => Arr::only((array) ($base['media'] ?? []), ['photos_min', 'photos_max', 'slots']),
                    'fields' => collect((array) ($base['fields'] ?? []))->map(fn (array $field) => Arr::only($field, ['key', 'type', 'label']))->all(),
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'tenant_registration_customization',
            $this->schema(),
        );
        $personalization = json_decode($result['text'], true, flags: JSON_THROW_ON_ERROR);
        $variationCode = (string) ($tenant->businessProfile->variation?->code ?? '');
        $override = $this->configuration($personalization, (string) ($base['engine'] ?? 'request'), $base, $variationCode);

        $tenant->profile->refresh();
        $content = (array) $tenant->profile->content;
        $content['app_configuration'] = $override;
        $branding = (array) ($personalization['branding'] ?? []);
        $generatedBranding = [
            'description_translations' => (array) ($branding['description'] ?? []),
            'tagline_translations' => (array) ($branding['tagline'] ?? []),
            'tagline' => $this->localized($branding['tagline'] ?? [], $tenant->locale),
            'services' => $this->localized($branding['services'] ?? [], $tenant->locale),
            'customers' => $this->localized($branding['customers'] ?? [], $tenant->locale),
            'style' => $this->localized($branding['style'] ?? [], $tenant->locale),
            'avoid' => $this->localized($branding['avoid'] ?? [], $tenant->locale),
            'generated_from_registration' => true,
        ];
        $existingBranding = (array) ($content['branding'] ?? []);
        $registrationSeed = (array) ($existingBranding['registration_seed'] ?? []);
        foreach ($generatedBranding as $key => $value) {
            if (! array_key_exists($key, $existingBranding)
                || blank($existingBranding[$key])
                || array_key_exists($key, $registrationSeed) && $existingBranding[$key] === $registrationSeed[$key]
                || $key === 'generated_from_registration') {
                $existingBranding[$key] = $value;
            }
        }
        unset($existingBranding['registration_seed']);
        $content['branding'] = $existingBranding;
        $content['ai_customization'] = [
            'status' => 'ready',
            'base_template' => $template->code,
            'specialization' => $personalization['specialization'] ?? [],
            'model' => $result['model'],
            'generated_at' => now()->toIso8601String(),
        ];
        $tenant->profile->update(['content' => $content]);
        $budget->record('tenant_registration_customization', $result['model'], $result['input_tokens'], $result['output_tokens'], $ownerId, tenantId: $tenant->id);
    }

    public function failed(Throwable $exception): void
    {
        $tenant = Tenant::with('profile')->find($this->tenantId);
        if ($tenant?->profile) {
            $this->setStatus($tenant, 'failed');
        }
    }

    private function instructions(): string
    {
        return <<<'PROMPT'
You create a safe tenant-specific configuration layer on top of an existing LOOKDO business template. Use the exact business description to specialize customer-facing wording, intake questions, photo instructions and, for booking templates, starter services. Also prepare the complete initial branding questionnaire immediately: a public business description, short tagline, services, target customers, visual style, and sensible image exclusions. The user must see these fields already filled when opening the workspace. For example, an antiques buyer specializing in furniture, paintings, icons, porcelain, glass, silver, watches, coins, medals, stamps and postcards needs useful object-specific labels and photo guidance rather than generic antiques wording.

Keep the base engine and capabilities unchanged. Do not invent executable code, integrations, legal claims, prices, valuations, guarantees, medical advice or hidden facts. Ask only for information and photos genuinely useful to this specialist. Keep the flow short: no more than 6 photo slots, 8 fields, 4 trust points and 6 starter services. At least one photo is required for request templates. For booking templates return no photo slots or intake fields and return 3 to 6 starter services. For request templates return an empty starter_services array. Produce natural, concise customer-facing copy in German, English, Russian and Ukrainian. Every translation must express the same meaning.
PROMPT;
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        $localized = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array_fill_keys(['de', 'en', 'ru', 'uk'], ['type' => 'string']),
            'required' => ['de', 'en', 'ru', 'uk'],
        ];
        $slot = [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => ['title' => $localized, 'instruction' => $localized, 'required' => ['type' => 'boolean']],
            'required' => ['title', 'instruction', 'required'],
        ];
        $field = [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['text', 'textarea', 'number', 'select']],
                'label' => $localized,
                'placeholder' => $localized,
                'required' => ['type' => 'boolean'],
                'options' => ['type' => 'array', 'maxItems' => 8, 'items' => $localized],
            ],
            'required' => ['type', 'label', 'placeholder', 'required', 'options'],
        ];
        $trust = [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'icon' => ['type' => 'string', 'enum' => ['shield', 'tool', 'star', 'clock', 'heart', 'measure']],
                'label' => $localized,
            ],
            'required' => ['icon', 'label'],
        ];
        $service = [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'name' => $localized,
                'description' => $localized,
                'duration' => ['type' => 'integer', 'minimum' => 15, 'maximum' => 480],
            ],
            'required' => ['name', 'description', 'duration'],
        ];

        return [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'specialization' => $localized,
                'hero' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'properties' => ['eyebrow' => $localized, 'title' => $localized, 'text' => $localized, 'action' => $localized],
                    'required' => ['eyebrow', 'title', 'text', 'action'],
                ],
                'submit_label' => $localized,
                'success_title' => $localized,
                'success_text' => $localized,
                'condition_context' => $localized,
                'trust' => ['type' => 'array', 'maxItems' => 4, 'items' => $trust],
                'photo_slots' => ['type' => 'array', 'maxItems' => 6, 'items' => $slot],
                'fields' => ['type' => 'array', 'maxItems' => 8, 'items' => $field],
                'starter_services' => ['type' => 'array', 'maxItems' => 6, 'items' => $service],
                'branding' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'description' => $localized,
                        'tagline' => $localized,
                        'services' => $localized,
                        'customers' => $localized,
                        'style' => $localized,
                        'avoid' => $localized,
                    ],
                    'required' => ['description', 'tagline', 'services', 'customers', 'style', 'avoid'],
                ],
            ],
            'required' => ['specialization', 'hero', 'submit_label', 'success_title', 'success_text', 'condition_context', 'trust', 'photo_slots', 'fields', 'starter_services', 'branding'],
        ];
    }

    /** @param array<string, mixed> $personalization @param array<string, mixed> $base */
    private function configuration(array $personalization, string $engine, array $base, string $variationCode): array
    {
        $hero = Arr::only((array) ($personalization['hero'] ?? []), ['eyebrow', 'title', 'text', 'action']);
        $hero['subtitle'] = $hero['text'] ?? [];
        $override = [
            'hero' => $hero,
            'trust' => array_values(array_slice((array) ($personalization['trust'] ?? []), 0, 4)),
            'submit' => ['label' => $personalization['submit_label'] ?? []],
            'success' => ['title' => $personalization['success_title'] ?? [], 'text' => $personalization['success_text'] ?? []],
            'condition_assessment' => ['enabled' => true, 'context' => $personalization['condition_context'] ?? []],
        ];

        if ($engine === 'booking') {
            $services = collect((array) ($personalization['starter_services'] ?? []))
                ->take(6)
                ->map(fn (array $service) => [
                    'name' => $service['name'],
                    'description' => $service['description'],
                    'duration' => max(15, min(480, (int) $service['duration'])),
                    'image' => data_get($base, 'hero.image'),
                ])->values()->all();
            if ($services !== []) {
                $override['starter_services'] = $services;
            }

            return $override;
        }

        if ($variationCode === 'purchase.books') {
            return $override;
        }

        $slots = collect((array) ($personalization['photo_slots'] ?? []))->take(6)->map(function (array $slot, int $index): array {
            return [
                'key' => 'custom_photo_'.($index + 1),
                'role' => $index === 0 ? 'condition' : 'condition_detail',
                'title' => $slot['title'],
                'instruction' => $slot['instruction'],
                'required' => $index === 0 || (bool) $slot['required'],
            ];
        })->values();
        if ($slots->isEmpty()) {
            $slots = collect((array) data_get($base, 'media.slots', []))->take(6)->values();
        }
        $required = max(1, $slots->where('required', true)->count());
        $baseMax = max($required, (int) data_get($base, 'media.photos_max', $slots->count()));
        $override['media'] = [
            'photos_min' => $required,
            'photos_max' => min(12, max($required, $baseMax, $slots->count())),
            'slots' => $slots->all(),
        ];
        $override['fields'] = collect((array) ($personalization['fields'] ?? []))->take(8)->map(function (array $field, int $index): array {
            $type = in_array($field['type'] ?? null, ['text', 'textarea', 'number', 'select'], true) ? $field['type'] : 'text';
            $result = [
                'key' => 'custom_field_'.($index + 1),
                'type' => $type,
                'label' => $field['label'],
                'placeholder' => $field['placeholder'],
                'required' => (bool) $field['required'],
            ];
            if ($type === 'select') {
                $result['options'] = array_values(array_slice((array) ($field['options'] ?? []), 0, 8));
            }

            return $result;
        })->values()->all();

        return $override;
    }

    private function localized(mixed $value, string $locale): string
    {
        if (! is_array($value)) {
            return (string) $value;
        }

        return (string) ($value[$locale] ?? $value['de'] ?? $value['en'] ?? $value['ru'] ?? reset($value) ?: '');
    }

    private function setStatus(Tenant $tenant, string $status): void
    {
        $tenant->profile->refresh();
        $content = (array) $tenant->profile->content;
        $content['ai_customization'] = array_replace((array) ($content['ai_customization'] ?? []), [
            'status' => $status,
            'updated_at' => now()->toIso8601String(),
        ]);
        $tenant->profile->update(['content' => $content]);
    }
}

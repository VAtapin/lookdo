<?php

namespace App\Services;

use RuntimeException;

class LocalizedContentTranslationService
{
    public function __construct(private OpenAiService $openAi, private OpenAiBudgetService $budget) {}

    /**
     * @param  array<string, string>  $name
     * @param  array<string, string>  $description
     * @param  array<int, string>  $locales
     * @return array{name: array<string, string>, description: array<string, string>}
     */
    public function translateService(array $name, array $description, string $sourceLocale, array $locales, ?int $userId, int $tenantId): array
    {
        $locales = array_values(array_unique(array_intersect($locales, ['de', 'en', 'ru', 'uk'])));
        if (! in_array($sourceLocale, $locales, true)) {
            $locales[] = $sourceLocale;
        }

        $targets = array_values(array_diff($locales, [$sourceLocale]));
        if ($targets === []) {
            return ['name' => $name, 'description' => $description];
        }

        $sourceName = trim((string) ($name[$sourceLocale] ?? ''));
        $sourceDescription = trim((string) ($description[$sourceLocale] ?? ''));
        if ($sourceName === '') {
            throw new RuntimeException('Enter the service name in the primary language.');
        }

        $localizedStrings = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array_fill_keys($targets, ['type' => 'string']),
            'required' => $targets,
        ];
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'name' => $localizedStrings,
                'description' => $localizedStrings,
            ],
            'required' => ['name', 'description'],
        ];

        $this->budget->ensureAvailable($userId);
        $result = $this->openAi->structured(
            'Translate a beauty or local-service catalogue entry from the specified source language into every requested target language. Preserve the exact meaning, structure, paragraph breaks, prices, durations, product names, symbols, and factual claims. Use natural customer-facing language. Do not add, remove, correct, or reinterpret the content. Return an empty description in every target language when the source description is empty.',
            json_encode([
                'source_locale' => $sourceLocale,
                'target_locales' => $targets,
                'name' => $sourceName,
                'description' => $sourceDescription,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'lookdo_service_translation',
            $schema,
        );
        $translated = json_decode($result['text'], true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($translated['name'] ?? null) || ! is_array($translated['description'] ?? null)) {
            throw new RuntimeException('OpenAI returned an incomplete service translation.');
        }

        foreach ($targets as $locale) {
            if (blank($translated['name'][$locale] ?? null)) {
                throw new RuntimeException('OpenAI returned an incomplete service translation.');
            }
            $name[$locale] = trim((string) $translated['name'][$locale]);
            $description[$locale] = (string) ($translated['description'][$locale] ?? '');
        }
        $name[$sourceLocale] = $sourceName;
        $description[$sourceLocale] = $sourceDescription;

        $this->budget->record('service_translation', $result['model'], $result['input_tokens'], $result['output_tokens'], $userId, tenantId: $tenantId);

        return ['name' => $name, 'description' => $description];
    }
}

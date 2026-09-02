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
    public function translateService(array $name, array $description, string $sourceLocale, array $locales, ?int $userId, int $tenantId, array $extraFields = []): array
    {
        return $this->translateFields(
            ['name' => $name, 'description' => $description, ...$extraFields],
            $sourceLocale,
            $locales,
            $userId,
            $tenantId,
            'service_translation',
        );
    }

    /**
     * @param  array<string, array<string, string>>  $fields
     * @param  array<int, string>  $locales
     * @return array<string, array<string, string>>
     */
    public function translateFields(array $fields, string $sourceLocale, array $locales, ?int $userId, int $tenantId, string $usageType = 'localized_content_translation'): array
    {
        $locales = array_values(array_unique(array_intersect($locales, ['de', 'en', 'ru', 'uk'])));
        if (! in_array($sourceLocale, $locales, true)) {
            $locales[] = $sourceLocale;
        }

        $targets = array_values(array_diff($locales, [$sourceLocale]));
        if ($targets === []) {
            return $fields;
        }

        $source = [];
        foreach ($fields as $field => $values) {
            $source[$field] = trim((string) ($values[$sourceLocale] ?? ''));
        }
        if (($source['name'] ?? $source['title'] ?? reset($source) ?: '') === '') {
            throw new RuntimeException('Enter the title in the primary language.');
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
                ...array_fill_keys(array_keys($fields), $localizedStrings),
            ],
            'required' => array_keys($fields),
        ];

        $this->budget->ensureAvailable($userId);
        $result = $this->openAi->structured(
            'Translate the supplied localized business content from the specified source language into every requested target language. Preserve the exact meaning, structure, paragraph breaks, prices, durations, product names, symbols, and factual claims. Use natural customer-facing language. Do not add, remove, correct, or reinterpret the content. For every empty source field, return an empty string in every target language.',
            json_encode([
                'source_locale' => $sourceLocale,
                'target_locales' => $targets,
                'fields' => $source,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'lookdo_localized_content_translation',
            $schema,
        );
        $translated = json_decode($result['text'], true, flags: JSON_THROW_ON_ERROR);
        foreach ($fields as $field => $values) {
            if (! is_array($translated[$field] ?? null)) {
                throw new RuntimeException('OpenAI returned an incomplete translation.');
            }
            foreach ($targets as $locale) {
                if ($source[$field] !== '' && blank($translated[$field][$locale] ?? null)) {
                    throw new RuntimeException('OpenAI returned an incomplete translation.');
                }
                $fields[$field][$locale] = (string) ($translated[$field][$locale] ?? '');
            }
            $fields[$field][$sourceLocale] = $source[$field];
        }

        $this->budget->record($usageType, $result['model'], $result['input_tokens'], $result['output_tokens'], $userId, tenantId: $tenantId);

        return $fields;
    }
}

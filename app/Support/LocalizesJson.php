<?php

namespace App\Support;

trait LocalizesJson
{
    public function localized(string $field, ?string $locale = null): string
    {
        $values = $this->getAttribute($field) ?: [];
        if (! is_array($values)) {
            return (string) $values;
        }
        $locale ??= app()->getLocale();

        return (string) ($values[$locale] ?? $values[config('app.fallback_locale')] ?? $values['en'] ?? reset($values) ?: '');
    }
}

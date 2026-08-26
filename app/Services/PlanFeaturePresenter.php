<?php

namespace App\Services;

use App\Models\Plan;

class PlanFeaturePresenter
{
    /** @return array<int, array{key:string,label:string,included:bool}> */
    public function forPlan(Plan $plan, string $locale): array
    {
        $values = $plan->entitlements->pluck('value', 'key');
        $number = fn (string $key, int $default = 0): int => (int) ($values->get($key, $default));
        $enabled = fn (string $key): bool => in_array(strtolower((string) $values->get($key, '0')), ['1', 'true', 'yes', 'on'], true);

        $requestLimit = $number('requests_monthly');
        $storage = $number('storage_mb');
        $staff = max(1, $number('staff_users', 1));
        $languages = max(1, min(4, $number('app_languages', 1)));
        $video = $enabled('video_enabled');
        $smsLimit = $number('sms_monthly_limit');
        $sms = $enabled('sms_enabled') && $smsLimit > 0;
        $advancedRetention = $enabled('repeat_visit_enabled') || $enabled('segments_enabled') || $enabled('vacancy_fill_enabled');
        $retention = $advancedRetention || $enabled('reminders_enabled') || $enabled('before_after_enabled');
        $ai = $enabled('ai_media_enabled') || $enabled('ai_communication_enabled');
        $integrations = array_values(array_filter([
            $enabled('telegram_integration') ? 'Telegram' : null,
            $enabled('vk_integration') ? 'VK' : null,
        ]));

        return [
            $this->row('requests', $enabled('request_enabled'), $requestLimit === 0 ? $this->label('request_unlimited', $locale) : $this->label('request_limited', $locale, ['value' => $requestLimit])),
            $this->row('booking', $enabled('booking_enabled'), $this->label('booking', $locale)),
            $this->row('sms', $sms, $this->label($sms ? 'sms_limited' : 'sms_disabled', $locale, ['value' => $smsLimit])),
            $this->row('storage', $storage > 0, $storage >= 1024 ? $this->label('storage_gb', $locale, ['value' => $this->formatNumber($storage / 1024)]) : $this->label('storage_mb', $locale, ['value' => $storage])),
            $this->row('staff', true, $this->label($staff === 1 ? 'staff_one' : 'staff_many', $locale, ['value' => $staff])),
            $this->row('languages', true, $this->label($languages === 1 ? 'languages_one' : 'languages_many', $locale, ['value' => $languages])),
            $this->row('video', $video, $video ? $this->label('video', $locale, ['seconds' => $number('video_max_seconds'), 'size' => $number('video_max_mb')]) : $this->label('video_disabled', $locale)),
            $this->row('custom_domain', $enabled('custom_domain'), $this->label('custom_domain', $locale)),
            $this->row('retention', $retention, $this->label($advancedRetention ? 'retention_advanced' : ($retention ? 'retention' : 'retention_disabled'), $locale)),
            $this->row('ai', $ai, $this->label($ai ? 'ai' : 'ai_disabled', $locale)),
            $this->row('integrations', $integrations !== [], $integrations === [] ? $this->label('integrations_disabled', $locale) : $this->label('integrations', $locale, ['value' => implode(' + ', $integrations)])),
        ];
    }

    /** @return array{key:string,label:string,included:bool} */
    private function row(string $key, bool $included, string $label): array
    {
        return compact('key', 'label', 'included');
    }

    /** @param array<string, int|string> $replacements */
    private function label(string $key, string $locale, array $replacements = []): string
    {
        $labels = config("plan_entitlements.public.$key", []);
        $text = (string) ($labels[$locale] ?? $labels['de'] ?? $labels['en'] ?? $key);
        foreach ($replacements as $name => $value) {
            $text = str_replace(':'.$name, (string) $value, $text);
        }

        return $text;
    }

    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}

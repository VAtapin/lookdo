<?php

namespace App\Services;

use App\Models\RequestTemplate;
use App\Models\Tenant;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TenantPresetService
{
    public function apply(Tenant $tenant, string $presetCode, bool $force = false): Tenant
    {
        $preset = config('tenant_presets.presets.'.$presetCode);
        if (! is_array($preset)) {
            throw new InvalidArgumentException('Unknown tenant preset: '.$presetCode);
        }

        $template = RequestTemplate::query()->where('code', $preset['template'])->firstOrFail();

        return DB::transaction(function () use ($tenant, $presetCode, $preset, $template, $force): Tenant {
            $tenantDefaults = (array) ($preset['tenant'] ?? []);
            $tenant->fill($force ? $tenantDefaults : $this->fillBlank($tenant->getAttributes(), $tenantDefaults))->save();

            $profile = $tenant->profile()->firstOrNew();
            $profileDefaults = Arr::except((array) ($preset['profile'] ?? []), ['enabled_locales', 'branding']);
            $profile->fill($force ? $profileDefaults : $this->fillBlank($profile->getAttributes(), $profileDefaults));
            $content = (array) $profile->content;
            $content['preset'] = ['code' => $presetCode, 'applied_at' => now()->toIso8601String()];
            $content['enabled_locales'] = array_values((array) data_get($preset, 'profile.enabled_locales', [$tenant->locale]));
            $content['branding'] = $force
                ? array_replace((array) ($content['branding'] ?? []), (array) data_get($preset, 'profile.branding', []))
                : array_replace((array) data_get($preset, 'profile.branding', []), (array) ($content['branding'] ?? []));
            $profile->content = $content;
            $profile->save();

            $tenant->businessProfile()->updateOrCreate(
                ['tenant_id' => $tenant->id],
                ['category_id' => $template->category_id, 'variation_id' => $template->variation_id, 'request_template_id' => $template->id],
            );

            foreach ((array) data_get($preset, 'configuration.starter_services', []) as $index => $service) {
                $values = [
                    'name' => $service['name'],
                    'description' => $service['description'] ?? [],
                    'image_path' => $service['image'] ?? null,
                    'duration_minutes' => $service['duration'] ?? 60,
                    'booking_enabled' => true,
                    'active' => true,
                ];
                $existing = $tenant->services()->where('sort_order', $index * 10)->first();
                if (! $existing) {
                    $tenant->services()->create($values + ['sort_order' => $index * 10]);
                } elseif ($force) {
                    $existing->update($values);
                }
            }

            if ($force) {
                $obsoleteImages = array_filter((array) data_get($preset, 'configuration.obsolete_portfolio_images', []));
                if ($obsoleteImages !== []) {
                    $tenant->portfolioItems()->whereIn('image_path', $obsoleteImages)->delete();
                }
            }

            foreach ((array) data_get($preset, 'configuration.starter_portfolio', []) as $index => $item) {
                $values = [
                    'title' => $item['title'] ?? [],
                    'description' => $item['description'] ?? [],
                    'image_path' => $item['image'] ?? null,
                    'before_image_path' => $item['before_image'] ?? null,
                    'after_image_path' => $item['after_image'] ?? null,
                    'featured' => $item['featured'] ?? false,
                    'published' => true,
                    'sort_order' => $index * 10,
                ];
                $identity = filled($values['before_image_path']) || filled($values['after_image_path'])
                    ? ['before_image_path' => $values['before_image_path'], 'after_image_path' => $values['after_image_path']]
                    : ['image_path' => $values['image_path']];
                $existing = $tenant->portfolioItems()->where($identity)->first();
                if (! $existing) {
                    $tenant->portfolioItems()->create($values);
                } elseif ($force) {
                    $existing->update($values);
                }
            }

            return $tenant->fresh(['profile', 'businessProfile.template', 'services', 'portfolioItems']);
        });
    }

    private function fillBlank(array $current, array $defaults): array
    {
        return collect($defaults)->mapWithKeys(fn ($value, $key) => [
            $key => blank($current[$key] ?? null) ? $value : $current[$key],
        ])->all();
    }
}

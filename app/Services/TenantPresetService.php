<?php

namespace App\Services;

use App\Models\RequestTemplate;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
            $tenant->fill($this->fillBlank($tenant->getAttributes(), $tenantDefaults))->save();

            $profile = $tenant->profile()->firstOrNew();
            $profileDefaults = Arr::except((array) ($preset['profile'] ?? []), ['enabled_locales', 'branding']);
            $profile->fill($this->fillBlank($profile->getAttributes(), $profileDefaults));
            $content = (array) $profile->content;
            $content['preset'] = ['code' => $presetCode, 'applied_at' => now()->toIso8601String()];
            $content['enabled_locales'] = array_values((array) ($content['enabled_locales'] ?? data_get($preset, 'profile.enabled_locales', [$tenant->locale])));
            $content['branding'] = array_replace((array) data_get($preset, 'profile.branding', []), (array) ($content['branding'] ?? []));
            $profile->content = $content;
            $profile->save();

            $tenant->businessProfile()->updateOrCreate(
                ['tenant_id' => $tenant->id],
                ['category_id' => $template->category_id, 'variation_id' => $template->variation_id, 'request_template_id' => $template->id],
            );

            $starterServices = (array) data_get($preset, 'configuration.starter_services', []);
            $replaceServices = $force && (bool) data_get($preset, 'configuration.replace_services', false);
            $existingServices = $tenant->services()->get();
            $keptServiceIds = [];
            foreach ($starterServices as $index => $service) {
                $values = [
                    'name' => $service['name'],
                    'description' => $service['description'] ?? [],
                    'inclusions' => $service['inclusions'] ?? [],
                    'result' => $service['result'] ?? [],
                    'image_path' => $service['image'] ?? null,
                    'duration_minutes' => $service['duration'] ?? 60,
                    'price' => $service['price'] ?? null,
                    'currency' => $service['currency'] ?? 'EUR',
                    'repeat_interval_days' => $service['repeat_interval_days'] ?? null,
                    'booking_enabled' => true,
                    'active' => true,
                    'archived_at' => null,
                ];
                $existing = $replaceServices
                    ? $existingServices->first(fn ($candidate) => data_get($candidate->name, 'uk') === data_get($service, 'name.uk'))
                    : $existingServices->firstWhere('sort_order', $index * 10);
                if (! $existing) {
                    $existing = $tenant->services()->create($values + ['sort_order' => $index * 10]);
                } elseif ($force) {
                    $existing->update($values + ['sort_order' => $index * 10]);
                }
                $keptServiceIds[] = $existing->id;
            }

            if ($replaceServices) {
                $tenant->services()->whereNotIn('id', $keptServiceIds)->get()->each(function ($service): void {
                    if ($service->appointments()->exists() || $service->portfolioItems()->exists()) {
                        $service->update(['active' => false, 'booking_enabled' => false, 'archived_at' => now(), 'sort_order' => 10000 + $service->id]);
                    } else {
                        $service->delete();
                    }
                });
            }

            if ($force && (bool) data_get($preset, 'configuration.replace_portfolio', false)) {
                $tenant->portfolioItems()->get()->each(function ($item) use ($tenant): void {
                    foreach (['image_path', 'video_path', 'before_image_path', 'after_image_path'] as $field) {
                        $path = $item->{$field};
                        if ($path && str_starts_with($path, 'tenant-app/'.$tenant->id.'/portfolio/')) {
                            Storage::disk('public')->delete($path);
                        }
                    }
                    $item->delete();
                });
            } elseif ($force) {
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
                    'video_path' => $item['video'] ?? null,
                    'before_image_path' => $item['before_image'] ?? null,
                    'after_image_path' => $item['after_image'] ?? null,
                    'featured' => $item['featured'] ?? false,
                    'published' => true,
                    'sort_order' => $index * 10,
                ];
                $identity = filled($values['video_path'])
                    ? ['video_path' => $values['video_path']]
                    : (filled($values['before_image_path']) || filled($values['after_image_path'])
                    ? ['before_image_path' => $values['before_image_path'], 'after_image_path' => $values['after_image_path']]
                    : ['image_path' => $values['image_path']]);
                $existing = $tenant->portfolioItems()->where($identity)->first();
                if (! $existing) {
                    $tenant->portfolioItems()->create($values);
                } elseif ($force) {
                    $existing->update($values);
                }
            }

            foreach ((array) data_get($preset, 'configuration.starter_reviews', []) as $review) {
                $receivedAt = CarbonImmutable::parse($review['received_at']);
                $values = [
                    'rating' => (int) ($review['rating'] ?? 5),
                    'body' => $review['body'] ?? null,
                    'master_reply' => $review['master_reply'] ?? null,
                    'published' => true,
                    'replied_at' => filled($review['master_reply'] ?? null) ? $receivedAt : null,
                ];
                $existing = $tenant->reviews()
                    ->where('author_name', $review['author_name'])
                    ->where('received_at', $receivedAt)
                    ->first();
                if (! $existing) {
                    $tenant->reviews()->create($values + [
                        'author_name' => $review['author_name'],
                        'received_at' => $receivedAt,
                    ]);
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

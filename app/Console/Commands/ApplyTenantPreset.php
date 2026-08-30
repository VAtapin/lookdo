<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantPresetService;
use Illuminate\Console\Command;

class ApplyTenantPreset extends Command
{
    protected $signature = 'lookdo:tenant-preset {preset : Preset code} {--tenant= : Tenant ID or slug; omitted only for an unambiguous preset name match} {--force : Replace preset-managed fields and services}';

    protected $description = 'Apply a reusable personalized preset to one tenant';

    public function handle(TenantPresetService $presets): int
    {
        $presetCode = (string) $this->argument('preset');
        $selector = trim((string) $this->option('tenant'));
        $preset = config('tenant_presets.presets.'.$presetCode);
        if (! is_array($preset)) {
            $this->error('Unknown preset: '.$presetCode);

            return self::FAILURE;
        }

        $query = Tenant::query();
        if ($selector !== '') {
            $query->where(fn ($builder) => $builder->where('slug', $selector)->when(ctype_digit($selector), fn ($q) => $q->orWhereKey((int) $selector)));
        } else {
            $query->where('name', data_get($preset, 'tenant.name'));
        }

        $matches = $query->get();
        if ($matches->count() !== 1) {
            $this->error('Expected exactly one tenant, found '.$matches->count().'. Pass --tenant=ID-or-slug.');

            return self::FAILURE;
        }

        $tenant = $presets->apply($matches->first(), $presetCode, (bool) $this->option('force'));
        $this->info(sprintf('Preset %s applied to %s (%s).', $presetCode, $tenant->name, $tenant->slug));

        return self::SUCCESS;
    }
}

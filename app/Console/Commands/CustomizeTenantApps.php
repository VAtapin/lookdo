<?php

namespace App\Console\Commands;

use App\Jobs\GenerateTenantAppCustomization;
use App\Models\Tenant;
use App\Services\OpenAiBudgetService;
use App\Services\OpenAiService;
use Illuminate\Console\Command;

class CustomizeTenantApps extends Command
{
    protected $signature = 'tenants:customize-app
        {--tenant= : Tenant ID or slug}
        {--missing-branding : Process only tenants whose registration branding has not been generated}';

    protected $description = 'Generate tenant app customization and prefill branding from the registration description';

    public function handle(OpenAiService $openAi, OpenAiBudgetService $budget): int
    {
        if (! $openAi->configured()) {
            $this->error('OPENAI_API_KEY is not configured.');

            return self::FAILURE;
        }

        $tenantOption = trim((string) $this->option('tenant'));
        $query = Tenant::query()->with(['profile', 'businessProfile.template']);
        if ($tenantOption !== '') {
            $query->where(fn ($builder) => $builder->where('slug', $tenantOption)
                ->orWhere('id', ctype_digit($tenantOption) ? (int) $tenantOption : 0));
        }

        $tenants = $query->get()->filter(function (Tenant $tenant): bool {
            if (! $tenant->profile || ! $tenant->businessProfile?->template) {
                return false;
            }

            return ! $this->option('missing-branding')
                || data_get($tenant->profile->content, 'branding.generated_from_registration') !== true;
        })->values();

        if ($tenants->isEmpty()) {
            $this->info('No tenants require customization.');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($tenants as $tenant) {
            try {
                $this->line("Customizing {$tenant->name} ({$tenant->slug})...");
                (new GenerateTenantAppCustomization($tenant->id))->handle($openAi, $budget);
                $this->info("Ready: {$tenant->slug}");
            } catch (\Throwable $exception) {
                report($exception);
                $failed++;
                $this->error("Failed: {$tenant->slug} — {$exception->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}

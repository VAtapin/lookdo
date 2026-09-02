<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantBackupService;
use Illuminate\Console\Command;

class CreateTenantBackup extends Command
{
    protected $signature = 'backup:tenant {tenant? : Tenant ID or slug} {--all : Back up every tenant}';

    protected $description = 'Create isolated backups for one tenant or every tenant';

    public function handle(TenantBackupService $backups): int
    {
        $identifier = $this->argument('tenant');
        if (! $identifier && ! $this->option('all')) {
            $this->error('Specify a tenant ID/slug or use --all.');

            return self::INVALID;
        }

        $query = Tenant::query()->orderBy('id');
        if ($identifier) {
            $query->where(fn ($builder) => $builder->where('id', $identifier)->orWhere('slug', $identifier));
        }
        $tenants = $query->get();
        if ($tenants->isEmpty()) {
            $this->error('No matching tenant was found.');

            return self::FAILURE;
        }

        $failed = false;
        foreach ($tenants as $tenant) {
            try {
                $manifest = $backups->create($tenant, 'scheduled');
                $this->info($tenant->slug.': '.$manifest['name']);
            } catch (\Throwable $exception) {
                $failed = true;
                $this->error($tenant->slug.': '.$exception->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

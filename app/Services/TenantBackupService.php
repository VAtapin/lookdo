<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class TenantBackupService
{
    /** Tenant-owned data in dependency order. Shared platform catalogues stay global. */
    private const TABLES = [
        'tenant_profiles',
        'tenant_domains',
        'tenant_users',
        'subscriptions',
        'subscription_payments',
        'tenant_entitlement_overrides',
        'business_classifications',
        'tenant_business_profiles',
        'tenant_services',
        'tenant_portfolio_items',
        'tenant_customers',
        'tenant_client_tokens',
        'tenant_requests',
        'tenant_request_values',
        'tenant_resources',
        'tenant_appointments',
        'tenant_media',
        'tenant_messages',
        'tenant_push_subscriptions',
        'tenant_working_hours',
        'tenant_calendar_blocks',
        'tenant_reminders',
        'tenant_reviews',
        'tenant_social_connections',
        'tenant_social_provider_configs',
        'tenant_social_drafts',
        'tenant_segments',
        'tenant_customer_segment',
        'sms_messages',
        'image_credit_purchases',
        'legal_acceptances',
        'ai_usage_records',
        'audit_logs',
    ];

    private const STORAGE_PREFIXES = [
        'tenant-app',
        'tenant-social',
        'tenant-branding',
        'tenant-services',
        'tenant-logo',
        'tenant-logos',
        'tenant-portfolio',
    ];

    public function create(Tenant $tenant, string $reason = 'manual', bool $rotate = true): array
    {
        $this->ensureRequirements();
        $directory = $this->tenantPath($tenant);
        File::ensureDirectoryExists($directory, 0750, true);

        $name = 'tenant-'.$tenant->id.'-'.now()->format('Y-m-d_H-i-s-u');
        $archivePath = $directory.DIRECTORY_SEPARATOR.$name.'.zip';
        $payload = DB::transaction(fn (): array => $this->payload($tenant->fresh()), 3);

        $zip = new ZipArchive;
        if ($zip->open($archivePath.'.partial', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the tenant backup archive.');
        }

        $zip->addFromString('database.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $fileCount = $this->addTenantFiles($zip, $tenant);
        if (! $zip->close()) {
            File::delete($archivePath.'.partial');
            throw new RuntimeException('Tenant backup archive could not be finalized.');
        }
        File::move($archivePath.'.partial', $archivePath);

        $manifest = [
            'name' => $name,
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name,
            'tenant_slug' => $tenant->slug,
            'scope' => 'full_tenant',
            'reason' => $reason,
            'created_at' => now()->toIso8601String(),
            'archive' => basename($archivePath),
            'bytes' => File::size($archivePath),
            'sha256' => hash_file('sha256', $archivePath),
            'rows' => collect($payload['tables'])->map(fn (array $rows): int => count($rows))->all(),
            'user_count' => count($payload['users'] ?? []),
            'file_count' => $fileCount,
        ];
        File::put($directory.DIRECTORY_SEPARATOR.$name.'.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if ($rotate) {
            $this->rotate($tenant);
        }

        return $manifest;
    }

    public function list(Tenant $tenant): array
    {
        $directory = $this->tenantPath($tenant);
        File::ensureDirectoryExists($directory, 0750, true);

        return collect(File::glob($directory.DIRECTORY_SEPARATOR.'tenant-'.$tenant->id.'-*.json'))
            ->sortDesc()
            ->map(function (string $path): array {
                $manifest = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

                return $manifest + ['name' => pathinfo($path, PATHINFO_FILENAME)];
            })
            ->values()
            ->all();
    }

    public function verify(Tenant $tenant, string $name): array
    {
        $manifest = $this->manifest($tenant, $name);
        $archivePath = $this->tenantPath($tenant).DIRECTORY_SEPARATOR.basename((string) $manifest['archive']);
        $errors = [];
        if (! File::exists($archivePath)) {
            $errors[] = 'Archive is missing.';
        } elseif (! hash_equals((string) $manifest['sha256'], (string) hash_file('sha256', $archivePath))) {
            $errors[] = 'Archive checksum mismatch.';
        } else {
            $zip = new ZipArchive;
            $opened = $zip->open($archivePath) === true;
            if (! $opened || $zip->locateName('database.json') === false) {
                $errors[] = 'Archive is unreadable or database.json is missing.';
            }
            if ($opened) {
                $zip->close();
            }
        }

        return ['name' => $name, 'valid' => $errors === [], 'errors' => $errors];
    }

    public function restore(Tenant $tenant, string $name): array
    {
        $verification = $this->verify($tenant, $name);
        if (! $verification['valid']) {
            throw new RuntimeException('Tenant backup is invalid: '.implode(' ', $verification['errors']));
        }

        // This is intentionally created before any destructive operation.
        $safety = $this->create($tenant->fresh(), 'pre-restore', false);
        $manifest = $this->manifest($tenant, $name);
        $archivePath = $this->tenantPath($tenant).DIRECTORY_SEPARATOR.basename((string) $manifest['archive']);
        $zip = new ZipArchive;
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Cannot open the tenant backup archive.');
        }
        $databaseJson = $zip->getFromName('database.json');
        if (! is_string($databaseJson)) {
            $zip->close();
            throw new RuntimeException('Tenant backup database payload is missing.');
        }
        $payload = json_decode($databaseJson, true, flags: JSON_THROW_ON_ERROR);
        if ((int) ($payload['tenant']['id'] ?? 0) !== (int) $tenant->id) {
            $zip->close();
            throw new RuntimeException('This backup belongs to a different tenant.');
        }

        $this->assertNoIdConflicts($tenant, $payload['tables'] ?? []);
        DB::transaction(function () use ($tenant, $payload): void {
            $tables = $payload['tables'] ?? [];
            $isFull = (int) ($payload['format'] ?? 1) >= 2;
            $preserved = $isFull ? [] : $this->preservedTenantData($tenant);
            $userMap = $this->restoreUsers($tenant, $payload['users'] ?? []);
            $tables = $this->remapUserReferences($tables, $userMap);
            $this->assertUniqueTenantValues($tenant, $payload['tenant'] ?? [], $tables);
            $this->deleteTenantData($tenant, array_keys($tables));
            $this->insertTenantData($tables);
            $this->restoreTenantRecord($tenant, $payload['tenant'] ?? []);
            $this->restorePreservedTenantData($tenant, $preserved);
        }, 3);
        $this->restoreFiles($zip, $tenant);
        $zip->close();

        return [
            'restored' => true,
            'name' => $name,
            'tenant_id' => $tenant->id,
            'safety_backup' => $safety['name'],
        ];
    }

    public function delete(Tenant $tenant, string $name): void
    {
        $manifest = $this->manifest($tenant, $name);
        $directory = $this->tenantPath($tenant);
        File::delete([
            $directory.DIRECTORY_SEPARATOR.$name.'.json',
            $directory.DIRECTORY_SEPARATOR.basename((string) $manifest['archive']),
        ]);
    }

    public function path(): string
    {
        return (string) config('backup.tenant_path');
    }

    private function payload(Tenant $tenant): array
    {
        $tables = [];
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $tables[$table] = $this->rowsForTenant($table, $tenant->id);
        }

        return [
            'format' => 2,
            'scope' => 'full_tenant',
            'created_at' => now()->toIso8601String(),
            'tenant' => $tenant->getAttributes(),
            'users' => $this->tenantUsers($tenant->id),
            'tables' => $tables,
        ];
    }

    private function rowsForTenant(string $table, int $tenantId): array
    {
        if (Schema::hasColumn($table, 'tenant_id')) {
            return DB::table($table)->where('tenant_id', $tenantId)->orderBy($this->orderColumn($table))->get()->map(fn ($row): array => (array) $row)->all();
        }
        if ($table === 'tenant_request_values') {
            return DB::table($table)->whereIn('request_id', DB::table('tenant_requests')->where('tenant_id', $tenantId)->select('id'))->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }
        if ($table === 'tenant_customer_segment') {
            return DB::table($table)->whereIn('tenant_segment_id', DB::table('tenant_segments')->where('tenant_id', $tenantId)->select('id'))->orderBy('tenant_segment_id')->get()->map(fn ($row): array => (array) $row)->all();
        }
        if ($table === 'subscription_payments') {
            return DB::table($table)->whereIn('subscription_id', DB::table('subscriptions')->where('tenant_id', $tenantId)->select('id'))->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return [];
    }

    private function tenantUsers(int $tenantId): array
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('tenant_users')) {
            return [];
        }

        return DB::table('users')
            ->whereIn('id', DB::table('tenant_users')->where('tenant_id', $tenantId)->select('user_id'))
            ->orderBy('id')
            ->get()
            ->map(fn ($row): array => (array) $row)
            ->all();
    }

    private function restoreUsers(Tenant $tenant, array $users): array
    {
        if ($users === [] || ! Schema::hasTable('users')) {
            return [];
        }
        $columns = array_flip(Schema::getColumnListing('users'));
        $mapping = [];
        foreach ($users as $snapshot) {
            $oldId = (int) ($snapshot['id'] ?? 0);
            $email = (string) ($snapshot['email'] ?? '');
            if (! $oldId || $email === '') {
                continue;
            }
            $byId = DB::table('users')->where('id', $oldId)->first();
            $byEmail = DB::table('users')->where('email', $email)->first();
            $existing = $byId && (string) $byId->email === $email ? $byId : $byEmail;
            if ($existing) {
                $mapping[$oldId] = (int) $existing->id;
                $belongsToTenant = DB::table('tenant_users')->where('tenant_id', $tenant->id)->where('user_id', $existing->id)->exists();
                $shared = DB::table('tenant_users')->where('tenant_id', '!=', $tenant->id)->where('user_id', $existing->id)->exists();
                if ($belongsToTenant && ! $shared) {
                    $updates = array_intersect_key($snapshot, $columns);
                    unset($updates['id'], $updates['is_super_admin']);
                    DB::table('users')->where('id', $existing->id)->update($updates);
                }

                continue;
            }

            $row = array_intersect_key($snapshot, $columns);
            $row['is_super_admin'] = false;
            if ($byId) {
                unset($row['id']);
            }
            if (isset($row['id'])) {
                DB::table('users')->insert($row);
                $newId = (int) $row['id'];
            } else {
                $newId = (int) DB::table('users')->insertGetId($row);
            }
            $mapping[$oldId] = $newId;
        }

        return $mapping;
    }

    private function remapUserReferences(array $tables, array $mapping): array
    {
        $references = [
            'tenant_users' => ['user_id'],
            'tenant_messages' => ['sender_user_id'],
            'tenant_appointments' => ['staff_user_id'],
            'tenant_push_subscriptions' => ['user_id'],
            'tenant_resources' => ['user_id'],
            'tenant_social_connections' => ['connected_by_user_id'],
            'image_credit_purchases' => ['user_id'],
            'legal_acceptances' => ['user_id'],
            'ai_usage_records' => ['user_id'],
            'audit_logs' => ['actor_id'],
        ];
        foreach ($references as $table => $columns) {
            if (! isset($tables[$table])) {
                continue;
            }
            foreach ($tables[$table] as &$row) {
                foreach ($columns as $column) {
                    if (! empty($row[$column]) && isset($mapping[(int) $row[$column]])) {
                        $row[$column] = $mapping[(int) $row[$column]];
                    }
                }
                if ($table === 'tenant_users') {
                    unset($row['id']);
                }
            }
            unset($row);
        }

        return $tables;
    }

    private function assertUniqueTenantValues(Tenant $tenant, array $snapshot, array $tables): void
    {
        $slug = $snapshot['slug'] ?? null;
        if ($slug && DB::table('tenants')->where('slug', $slug)->where('id', '!=', $tenant->id)->exists()) {
            throw new RuntimeException('The backed-up tenant slug is now used by another tenant.');
        }
        foreach ($tables['tenant_domains'] ?? [] as $domain) {
            if (! empty($domain['domain']) && DB::table('tenant_domains')->where('domain', $domain['domain'])->where('tenant_id', '!=', $tenant->id)->exists()) {
                throw new RuntimeException('A backed-up domain is now used by another tenant.');
            }
        }
    }

    private function orderColumn(string $table): string
    {
        return Schema::hasColumn($table, 'id') ? 'id' : (Schema::hasColumn($table, 'created_at') ? 'created_at' : 'tenant_id');
    }

    private function deleteTenantData(Tenant $tenant, array $includedTables): void
    {
        if (in_array('tenant_domains', $includedTables, true)) {
            DB::table('tenants')->where('id', $tenant->id)->update(['primary_domain_id' => null]);
        }
        foreach (array_reverse(self::TABLES) as $table) {
            if (! in_array($table, $includedTables, true) || ! Schema::hasTable($table)) {
                continue;
            }
            if (Schema::hasColumn($table, 'tenant_id')) {
                DB::table($table)->where('tenant_id', $tenant->id)->delete();
            } elseif ($table === 'tenant_request_values') {
                DB::table($table)->whereIn('request_id', DB::table('tenant_requests')->where('tenant_id', $tenant->id)->select('id'))->delete();
            } elseif ($table === 'tenant_customer_segment') {
                DB::table($table)->whereIn('tenant_segment_id', DB::table('tenant_segments')->where('tenant_id', $tenant->id)->select('id'))->delete();
            } elseif ($table === 'subscription_payments') {
                DB::table($table)->whereIn('subscription_id', DB::table('subscriptions')->where('tenant_id', $tenant->id)->select('id'))->delete();
            }
        }
    }

    private function restoreTenantRecord(Tenant $tenant, array $snapshot): void
    {
        $columns = array_values(array_intersect(array_keys($snapshot), Schema::getColumnListing('tenants')));
        $columns = array_values(array_diff($columns, ['id']));
        DB::table('tenants')->where('id', $tenant->id)->update(collect($snapshot)->only($columns)->all());
    }

    private function preservedTenantData(Tenant $tenant): array
    {
        if (! Schema::hasTable('tenant_profiles')) {
            return [];
        }
        $columns = array_values(array_intersect(
            ['image_generation_free_used', 'image_generation_credits'],
            Schema::getColumnListing('tenant_profiles'),
        ));

        return $columns === []
            ? []
            : (array) DB::table('tenant_profiles')->where('tenant_id', $tenant->id)->first($columns);
    }

    private function restorePreservedTenantData(Tenant $tenant, array $preserved): void
    {
        if ($preserved !== [] && Schema::hasTable('tenant_profiles')) {
            DB::table('tenant_profiles')->where('tenant_id', $tenant->id)->update($preserved);
        }
    }

    private function insertTenantData(array $tables): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || empty($tables[$table])) {
                continue;
            }
            $columns = array_flip(Schema::getColumnListing($table));
            $rows = collect($tables[$table])->map(fn (array $row): array => array_intersect_key($row, $columns))->all();
            $customerDuplicates = [];
            if ($table === 'tenant_customers' && isset($columns['possible_duplicate_of_id'])) {
                foreach ($rows as &$row) {
                    if (! empty($row['possible_duplicate_of_id'])) {
                        $customerDuplicates[(int) $row['id']] = (int) $row['possible_duplicate_of_id'];
                        $row['possible_duplicate_of_id'] = null;
                    }
                }
                unset($row);
            }
            $columnCount = max(1, count($rows[0] ?? []));
            foreach (array_chunk($rows, max(1, intdiv(800, $columnCount))) as $chunk) {
                DB::table($table)->insert($chunk);
            }
            foreach ($customerDuplicates as $customerId => $duplicateId) {
                DB::table('tenant_customers')->where('id', $customerId)->update(['possible_duplicate_of_id' => $duplicateId]);
            }
        }
    }

    private function assertNoIdConflicts(Tenant $tenant, array $tables): void
    {
        foreach (self::TABLES as $table) {
            if ($table === 'tenant_users' || ! Schema::hasTable($table) || ! Schema::hasColumn($table, 'id') || ! Schema::hasColumn($table, 'tenant_id') || empty($tables[$table])) {
                continue;
            }
            $ids = array_filter(array_column($tables[$table], 'id'));
            if ($ids !== [] && DB::table($table)->whereIn('id', $ids)->where('tenant_id', '!=', $tenant->id)->exists()) {
                throw new RuntimeException("Cannot restore {$table}: an ID is already used by another tenant.");
            }
        }
        foreach (['tenant_request_values', 'subscription_payments'] as $table) {
            if (! Schema::hasTable($table) || empty($tables[$table])) {
                continue;
            }
            $snapshotIds = array_filter(array_column($tables[$table], 'id'));
            $ownedIds = array_column($this->rowsForTenant($table, $tenant->id), 'id');
            if ($snapshotIds !== [] && DB::table($table)->whereIn('id', $snapshotIds)->whereNotIn('id', $ownedIds ?: [0])->exists()) {
                throw new RuntimeException("Cannot restore {$table}: an ID is already used by another tenant.");
            }
        }
    }

    private function addTenantFiles(ZipArchive $zip, Tenant $tenant): int
    {
        $disk = Storage::disk('public');
        $count = 0;
        foreach ($this->tenantDirectories($tenant) as $directory) {
            foreach ($disk->allFiles($directory) as $file) {
                $path = $disk->path($file);
                if (File::isFile($path) && $zip->addFile($path, 'files/public/'.str_replace('\\', '/', $file))) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function restoreFiles(ZipArchive $zip, Tenant $tenant): void
    {
        $disk = Storage::disk('public');
        foreach ($this->tenantDirectories($tenant) as $directory) {
            $disk->deleteDirectory($directory);
        }
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = (string) $zip->getNameIndex($index);
            if (! str_starts_with($entry, 'files/public/') || str_ends_with($entry, '/')) {
                continue;
            }
            $relative = substr($entry, strlen('files/public/'));
            if (! $this->isTenantFile($relative, $tenant)) {
                throw new RuntimeException('Unsafe file path found in tenant backup.');
            }
            $stream = $zip->getStream($entry);
            if (! is_resource($stream) || ! $disk->put($relative, $stream)) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                throw new RuntimeException('A tenant file could not be restored.');
            }
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function isTenantFile(string $relative, Tenant $tenant): bool
    {
        $normalized = str_replace('\\', '/', $relative);
        if (str_contains($normalized, '../') || str_starts_with($normalized, '/')) {
            return false;
        }

        return collect($this->tenantDirectories($tenant))->contains(fn (string $directory): bool => str_starts_with($normalized, $directory.'/'));
    }

    private function tenantDirectories(Tenant $tenant): array
    {
        return array_map(fn (string $prefix): string => $prefix.'/'.$tenant->id, self::STORAGE_PREFIXES);
    }

    private function manifest(Tenant $tenant, string $name): array
    {
        if (basename($name) !== $name || ! preg_match('/^tenant-'.preg_quote((string) $tenant->id, '/').'-[\w-]+$/', $name)) {
            throw new RuntimeException('Invalid tenant backup name.');
        }
        $path = $this->tenantPath($tenant).DIRECTORY_SEPARATOR.$name.'.json';
        if (! File::exists($path)) {
            throw new RuntimeException('Tenant backup was not found.');
        }
        $manifest = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        if ((int) ($manifest['tenant_id'] ?? 0) !== (int) $tenant->id) {
            throw new RuntimeException('Tenant backup ownership mismatch.');
        }

        return $manifest;
    }

    private function rotate(Tenant $tenant): void
    {
        collect($this->list($tenant))
            ->slice(max(1, (int) config('backup.tenant_keep', 14)))
            ->each(fn (array $backup) => $this->delete($tenant, $backup['name']));
    }

    private function ensureRequirements(): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension is required.');
        }
        File::ensureDirectoryExists($this->path(), 0750, true);
    }

    private function tenantPath(Tenant $tenant): string
    {
        return rtrim($this->path(), '/\\').DIRECTORY_SEPARATOR.'tenant-'.$tenant->id;
    }
}

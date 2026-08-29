<?php

namespace App\Services;

use App\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DomainService
{
    public function __construct(private readonly PleskApiClient $plesk) {}

    public function provision(TenantDomain $domain): TenantDomain
    {
        if ($domain->type === 'platform' || $domain->provisioning_status === 'provisioned') {
            return $domain;
        }

        if (! $this->plesk->configured()) {
            $domain->update([
                'provisioning_status' => 'configuration_required',
                'last_error' => 'Plesk API is not configured.',
            ]);

            return $domain->refresh();
        }

        $domain->forceFill(['provisioning_status' => 'provisioning', 'last_error' => null])->save();

        try {
            $result = $this->plesk->call('domalias', [
                '--create', $domain->domain,
                '-domain', (string) config('services.plesk.subscription_domain'),
                '-status', 'enabled',
                '-mail', 'false',
                '-web', 'true',
                '-seo-redirect', 'false',
            ]);
        } catch (RuntimeException $exception) {
            if (! str_contains(strtolower($exception->getMessage()), 'already exists')) {
                $domain->update(['provisioning_status' => 'failed', 'last_error' => $exception->getMessage()]);
                throw $exception;
            }
            $result = ['stdout' => 'Domain alias already exists.'];
        }

        $domain->update([
            'provisioning_status' => 'provisioned',
            'plesk_site_id' => $this->reference($result['stdout'] ?? ''),
            'provisioned_at' => now(),
            'last_error' => null,
        ]);

        return $domain->refresh();
    }

    public function verify(TenantDomain $domain): TenantDomain
    {
        if ($domain->type === 'platform') {
            return $domain;
        }

        $domain = $this->provision($domain);
        if ($domain->provisioning_status !== 'provisioned') {
            return $domain;
        }
        $domain->forceFill(['status' => 'verifying', 'last_checked_at' => now(), 'last_error' => null])->save();
        $target = parse_url(config('app.url'), PHP_URL_HOST) ?: config('tenancy.platform_domain');
        $domainIps = gethostbynamel($domain->domain) ?: [];
        $targetIps = gethostbynamel($target) ?: [];

        if (! array_intersect($domainIps, $targetIps)) {
            $domain->update(['status' => 'failed', 'last_error' => 'DNS does not point to LOOKDO yet.']);

            return $domain->refresh();
        }

        $domain->update(['status' => 'ssl_pending', 'verified_at' => now(), 'ssl_status' => 'pending']);
        $this->issueCertificate($domain);

        return $domain->refresh();
    }

    public function remove(TenantDomain $domain): void
    {
        if ($domain->type === 'platform') {
            throw new RuntimeException('A platform domain cannot be removed.');
        }

        if ($this->plesk->configured() && in_array($domain->provisioning_status, ['provisioned', 'provisioning'], true)) {
            try {
                $this->plesk->call('domalias', ['--delete', $domain->domain]);
            } catch (RuntimeException $exception) {
                if (! str_contains(strtolower($exception->getMessage()), 'does not exist')) {
                    throw $exception;
                }
            }
        }

        DB::transaction(function () use ($domain) {
            $tenant = $domain->tenant()->lockForUpdate()->firstOrFail();
            if ($tenant->primary_domain_id === $domain->id || $domain->is_primary) {
                $platform = $tenant->domains()->where('type', 'platform')->first();
                $platform?->update(['is_primary' => true]);
                $tenant->update(['primary_domain_id' => $platform?->id]);
            }
            $domain->delete();
        });
    }

    public function disable(TenantDomain $domain): TenantDomain
    {
        if ($domain->type === 'platform') {
            throw new RuntimeException('A platform domain cannot be disabled.');
        }

        if ($this->plesk->configured() && $domain->provisioning_status === 'provisioned') {
            $this->plesk->call('domalias', ['--off', $domain->domain]);
        }

        DB::transaction(function () use ($domain) {
            $tenant = $domain->tenant()->lockForUpdate()->firstOrFail();
            $domain->update(['status' => 'disabled', 'is_primary' => false, 'ssl_status' => 'disabled']);
            if ($tenant->primary_domain_id === $domain->id) {
                $platform = $tenant->domains()->where('type', 'platform')->first();
                $platform?->update(['is_primary' => true]);
                $tenant->update(['primary_domain_id' => $platform?->id]);
            }
        });

        return $domain->refresh();
    }

    private function issueCertificate(TenantDomain $domain): void
    {
        if (! $this->plesk->configured()) {
            $domain->update(['ssl_status' => 'configuration_required', 'last_error' => 'Plesk API is not configured.']);

            return;
        }

        $email = (string) config('services.plesk.letsencrypt_email');
        if ($email === '') {
            $domain->update(['ssl_status' => 'configuration_required', 'last_error' => 'Plesk Let\'s Encrypt email is not configured.']);

            return;
        }

        try {
            $this->plesk->call('extension', ['--exec', 'letsencrypt', 'cli.php', '-d', $domain->domain, '-m', $email]);
            DB::transaction(function () use ($domain) {
                $tenant = $domain->tenant()->lockForUpdate()->firstOrFail();
                $tenant->domains()->update(['is_primary' => false]);
                $domain->update([
                    'status' => 'active',
                    'ssl_status' => 'active',
                    'ssl_issued_at' => now(),
                    'is_primary' => true,
                    'last_error' => null,
                ]);
                $tenant->update(['primary_domain_id' => $domain->id]);
            });
        } catch (RuntimeException $exception) {
            $domain->update(['ssl_status' => 'failed', 'last_error' => $exception->getMessage()]);
        }
    }

    private function reference(string $output): ?string
    {
        return preg_match('/\b(?:id|ID)\D+(\d+)\b/', $output, $matches) ? $matches[1] : null;
    }
}

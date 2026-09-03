<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    public function log(string $action, ?Model $subject = null, ?array $before = null, ?array $after = null, ?int $tenantId = null): void
    {
        AuditLog::create(['actor_id' => auth()->id(), 'tenant_id' => $tenantId, 'action' => $action, 'subject_type' => $subject?->getMorphClass(), 'subject_id' => $subject?->getKey(), 'before' => $before, 'after' => $after, 'ip_address' => request()?->ip()]);
        request()?->attributes->set('audit_recorded', true);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantReminder extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(TenantAppointment::class, 'appointment_id');
    }
}

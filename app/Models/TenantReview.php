<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantReview extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['published' => 'boolean', 'received_at' => 'datetime', 'replied_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(TenantRequest::class, 'request_id');
    }
}

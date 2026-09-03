<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantBookValuation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'recommended_purchase_price' => 'decimal:2',
            'context' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(TenantRequest::class, 'request_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['contact_snapshot' => 'array'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(RequestTemplate::class, 'request_template_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(TenantRequestValue::class, 'request_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(TenantMedia::class, 'request_id')->orderBy('sort_order');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TenantMessage::class, 'request_id')->orderBy('created_at');
    }
}

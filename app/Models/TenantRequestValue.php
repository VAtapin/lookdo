<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantRequestValue extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(TenantRequest::class, 'request_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSocialConnection extends Model
{
    protected $guarded = [];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'scopes' => 'array', 'expires_at' => 'datetime', 'last_validated_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }
}

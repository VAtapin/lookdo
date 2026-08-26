<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantMedia extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(TenantRequest::class, 'request_id');
    }

    public function portfolioItem(): BelongsTo
    {
        return $this->belongsTo(TenantPortfolioItem::class, 'portfolio_item_id');
    }
}

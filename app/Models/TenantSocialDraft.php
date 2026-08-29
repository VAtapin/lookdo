<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSocialDraft extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'publish_attempted_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function portfolioItem(): BelongsTo
    {
        return $this->belongsTo(TenantPortfolioItem::class, 'portfolio_item_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(TenantSocialConnection::class, 'social_connection_id');
    }
}

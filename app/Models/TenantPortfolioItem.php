<?php

namespace App\Models;

use App\Support\LocalizesJson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantPortfolioItem extends Model
{
    use LocalizesJson;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['title' => 'array', 'description' => 'array', 'featured' => 'boolean', 'published' => 'boolean'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(TenantService::class, 'service_id');
    }
}

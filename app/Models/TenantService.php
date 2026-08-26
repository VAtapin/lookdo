<?php

namespace App\Models;

use App\Support\LocalizesJson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantService extends Model
{
    use LocalizesJson;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['name' => 'array', 'description' => 'array', 'price' => 'decimal:2', 'booking_enabled' => 'boolean', 'active' => 'boolean'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(TenantAppointment::class, 'service_id');
    }

    public function portfolioItems(): HasMany
    {
        return $this->hasMany(TenantPortfolioItem::class, 'service_id');
    }
}

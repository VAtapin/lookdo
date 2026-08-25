<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessClassification extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['candidates' => 'array', 'confirmed_by_user_at' => 'datetime', 'confidence' => 'float'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BusinessCategory::class);
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(BusinessVariation::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

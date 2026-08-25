<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantBusinessProfile extends Model
{
    protected $guarded = [];

    public function category(): BelongsTo
    {
        return $this->belongsTo(BusinessCategory::class, 'category_id');
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(BusinessVariation::class, 'variation_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(RequestTemplate::class, 'request_template_id');
    }
}

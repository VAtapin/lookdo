<?php

namespace App\Models;

use App\Support\LocalizesJson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessCategory extends Model
{
    use LocalizesJson;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['name' => 'array', 'enabled' => 'boolean'];
    }

    public function variations(): HasMany
    {
        return $this->hasMany(BusinessVariation::class, 'category_id');
    }
}

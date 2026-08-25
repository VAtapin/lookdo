<?php

namespace App\Models;

use App\Support\LocalizesJson;
use Illuminate\Database\Eloquent\Model;

class PlatformPage extends Model
{
    use LocalizesJson;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['title' => 'array', 'content' => 'array', 'is_published' => 'boolean'];
    }
}

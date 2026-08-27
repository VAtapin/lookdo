<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImageCreditPurchase extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'unit_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'fulfilled_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

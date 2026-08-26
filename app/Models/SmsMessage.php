<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsMessage extends Model
{
    protected $guarded = [];

    protected $hidden = ['recipient', 'message', 'provider_payload'];

    protected $appends = ['recipient_masked'];

    protected function casts(): array
    {
        return [
            'recipient' => 'encrypted',
            'message' => 'encrypted',
            'provider_payload' => 'encrypted:array',
            'cost' => 'decimal:4',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function getRecipientMaskedAttribute(): string
    {
        $digits = preg_replace('/\D+/', '', (string) $this->recipient) ?: '';

        return $digits === '' ? '—' : '•••• '.substr($digits, -4);
    }
}

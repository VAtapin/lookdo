<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class SystemSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'json', 'is_secret' => 'boolean'];
    }

    public static function read(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('key', $key)->first();

        return $setting?->value ?? $default;
    }

    public static function readSecret(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('key', $key)->where('is_secret', true)->first();
        $value = $setting?->value;
        if (! is_string($value) || $value === '') {
            return $default;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return $default;
        }
    }

    public static function writeSecret(string $key, ?string $value): void
    {
        if (blank($value)) {
            static::query()->where('key', $key)->delete();

            return;
        }

        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => Crypt::encryptString($value), 'is_secret' => true],
        );
    }
}

<?php

namespace App\Models;

use App\Support\LocalizesJson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestTemplate extends Model
{
    use LocalizesJson;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['name' => 'array', 'configuration' => 'array', 'enabled' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BusinessCategory::class);
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(BusinessVariation::class);
    }

    /**
     * Resolve the complete template configuration from the oldest parent to
     * the current specialization. Database values override code presets at
     * every level, while a child always overrides its parent.
     */
    public function resolvedConfiguration(array $presets = []): array
    {
        $chain = [];
        $seen = [];
        $template = $this;

        while ($template && ! isset($seen[$template->code])) {
            $chain[] = $template;
            $seen[$template->code] = true;
            $template = filled($template->parent_code)
                ? self::query()->where('code', $template->parent_code)->first()
                : null;
        }

        $configuration = [];
        foreach (array_reverse($chain) as $item) {
            $configuration = array_replace_recursive(
                $configuration,
                (array) ($presets[$item->code] ?? []),
                (array) $item->configuration,
            );
        }

        return $configuration;
    }
}

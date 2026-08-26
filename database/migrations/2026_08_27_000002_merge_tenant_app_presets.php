<?php

use App\Models\RequestTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach ((array) config('tenant_apps.templates', []) as $code => $preset) {
            $template = RequestTemplate::where('code', $code)->first();
            if (! $template) {
                continue;
            }

            $template->update([
                'configuration' => array_replace_recursive((array) $template->configuration, (array) $preset),
                'version' => max(2, (int) $template->version),
            ]);
        }
    }

    public function down(): void
    {
        // Template configuration is editorial content; deployment rollbacks must not erase later admin changes.
    }
};

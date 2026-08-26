<?php

use App\Support\LegalContentSanitizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')->where('key', 'legal_dispute_statement')->delete();

            $placeholderSettings = [
                'legal_operator_name' => '[Betreibername in Super Admin ergänzen]',
                'legal_operator_address' => '[vollständige ladungsfähige Anschrift ergänzen]',
                'legal_representative' => '[vertretungsberechtigte Person ergänzen]',
                'legal_phone' => '[Telefonnummer ergänzen]',
                'legal_register' => '[falls vorhanden: Register und Registernummer ergänzen]',
                'legal_vat_id' => '[falls vorhanden: USt-IdNr. ergänzen]',
            ];

            DB::table('system_settings')->whereIn('key', array_keys($placeholderSettings))->get(['id', 'key', 'value'])->each(function ($setting) use ($placeholderSettings): void {
                if (json_decode((string) $setting->value, true) === $placeholderSettings[$setting->key]) {
                    DB::table('system_settings')->where('id', $setting->id)->update(['value' => json_encode('')]);
                }
            });
        }

        if (! Schema::hasTable('platform_pages')) {
            return;
        }

        DB::table('platform_pages')->select(['id', 'content'])->orderBy('id')->chunkById(100, function ($pages): void {
            foreach ($pages as $page) {
                $content = json_decode((string) $page->content, true);
                if (! is_array($content)) {
                    continue;
                }

                $cleaned = [];
                foreach ($content as $locale => $html) {
                    $cleaned[$locale] = is_string($html) ? LegalContentSanitizer::clean($html) : $html;
                }

                if ($cleaned !== $content) {
                    DB::table('platform_pages')->where('id', $page->id)->update([
                        'content' => json_encode($cleaned, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Removed legal notices and obsolete dispute-resolution content are intentionally not restored.
    }
};

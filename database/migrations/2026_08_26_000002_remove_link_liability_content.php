<?php

use App\Support\LegalContentSanitizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_pages')) {
            return;
        }

        DB::table('platform_pages')->where('key', 'widerruf')->delete();

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
        // Removed obsolete link-liability content is intentionally not restored.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (range(1, 6) as $number) {
            $suffix = str_pad((string) $number, 2, '0', STR_PAD_LEFT);
            $video = "/brand/tenants/ivanna-brows/portfolio/ivanna-work-video-{$suffix}.mp4";
            $poster = "/brand/tenants/ivanna-brows/portfolio/ivanna-work-video-{$suffix}-poster.jpg";

            DB::table('tenant_portfolio_items')
                ->where('video_path', $video)
                ->whereNull('image_path')
                ->update(['image_path' => $poster, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (range(1, 6) as $number) {
            $suffix = str_pad((string) $number, 2, '0', STR_PAD_LEFT);
            $poster = "/brand/tenants/ivanna-brows/portfolio/ivanna-work-video-{$suffix}-poster.jpg";

            DB::table('tenant_portfolio_items')
                ->where('image_path', $poster)
                ->update(['image_path' => null, 'updated_at' => now()]);
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tenantId = DB::table('tenants')->where('slug', 'ivanna-brows')->value('id');

        if (! $tenantId) {
            return;
        }

        $titles = [
            'ivanna-work-photo-01.png' => ['uk' => 'Природне оформлення брів', 'ru' => 'Естественное оформление бровей', 'de' => 'Natürliche Augenbrauenform'],
            'ivanna-work-photo-02.png' => ['uk' => 'Індивідуальна форма та відтінок', 'ru' => 'Индивидуальная форма и оттенок', 'de' => 'Individuelle Form und Farbe'],
            'ivanna-work-photo-03.png' => ['uk' => 'Результат ламінування брів', 'ru' => 'Результат ламинирования бровей', 'de' => 'Ergebnis der Brow-Laminierung'],
            'ivanna-work-photo-04.png' => ['uk' => 'М’яке натуральне оформлення', 'ru' => 'Мягкое естественное оформление', 'de' => 'Sanftes natürliches Styling'],
            'ivanna-work-photo-05.png' => ['uk' => 'Фарбування та догляд Zola', 'ru' => 'Окрашивание и уход Zola', 'de' => 'Färben und Pflege mit Zola'],
            'ivanna-work-photo-06.png' => ['uk' => 'Симетрична архітектура брів', 'ru' => 'Симметричная архитектура бровей', 'de' => 'Symmetrische Augenbrauenarchitektur'],
            'ivanna-work-photo-07.png' => ['uk' => 'Ламінування брів — крупний план', 'ru' => 'Ламинирование бровей — крупный план', 'de' => 'Brow-Laminierung im Detail'],
            'ivanna-work-video-01.mp4' => ['uk' => 'Процес оформлення брів', 'ru' => 'Процесс оформления бровей', 'de' => 'Augenbrauen-Styling im Prozess'],
            'ivanna-work-video-02.mp4' => ['uk' => 'Точна корекція форми', 'ru' => 'Точная коррекция формы', 'de' => 'Präzise Formkorrektur'],
            'ivanna-work-video-03.mp4' => ['uk' => 'Довготривала укладка брів', 'ru' => 'Долговременная укладка бровей', 'de' => 'Langanhaltendes Brow-Styling'],
            'ivanna-work-video-04.mp4' => ['uk' => 'Фарбування брів Zola', 'ru' => 'Окрашивание бровей Zola', 'de' => 'Augenbrauenfärben mit Zola'],
            'ivanna-work-video-05.mp4' => ['uk' => 'Догляд та відновлення брів', 'ru' => 'Уход и восстановление бровей', 'de' => 'Pflege und Regeneration'],
            'ivanna-work-video-06.mp4' => ['uk' => 'Фінальний результат процедури', 'ru' => 'Финальный результат процедуры', 'de' => 'Das fertige Ergebnis'],
        ];

        foreach ($titles as $filename => $title) {
            DB::table('portfolio_items')
                ->where('tenant_id', $tenantId)
                ->where(function ($query) use ($filename) {
                    $query->where('image_path', 'like', '%'.$filename)
                        ->orWhere('video_path', 'like', '%'.$filename);
                })
                ->update(['title' => json_encode($title, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        // Titles are content; rolling back code must not destroy later manual edits.
    }
};

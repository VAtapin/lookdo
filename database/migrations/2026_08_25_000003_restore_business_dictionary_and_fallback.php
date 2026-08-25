<?php

use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        app(DatabaseSeeder::class)->run();

        $now = now();
        $json = fn (mixed $value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        DB::table('business_categories')->updateOrInsert(
            ['code' => 'general-services'],
            ['name' => $json(['de' => 'Allgemeine Dienstleistungen', 'en' => 'General services', 'ru' => 'Другие услуги']), 'enabled' => true, 'sort_order' => 999, 'updated_at' => $now, 'created_at' => $now],
        );
        $categoryId = DB::table('business_categories')->where('code', 'general-services')->value('id');

        DB::table('business_variations')->updateOrInsert(
            ['code' => 'general-services.general'],
            ['category_id' => $categoryId, 'name' => $json(['de' => 'Universelle Anfrage', 'en' => 'Universal request', 'ru' => 'Универсальная заявка']), 'template_code' => 'general-services.general', 'enabled' => true, 'priority' => 1, 'updated_at' => $now, 'created_at' => $now],
        );
        $variationId = DB::table('business_variations')->where('code', 'general-services.general')->value('id');

        DB::table('request_templates')->updateOrInsert(
            ['code' => 'general-services.general'],
            ['category_id' => $categoryId, 'variation_id' => $variationId, 'parent_code' => null, 'name' => $json(['de' => 'Universelle Anfrage', 'en' => 'Universal request', 'ru' => 'Универсальная заявка']), 'configuration' => $json(['primary_action_label' => ['de' => 'Aufgabe zeigen', 'en' => 'Show the task', 'ru' => 'Показать задачу'], 'media' => ['photos_min' => 1, 'photos_max' => 5, 'video_allowed' => true], 'fields' => []]), 'enabled' => true, 'version' => 1, 'sort_order' => 999, 'updated_at' => $now, 'created_at' => $now],
        );

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'default_request_template_code'],
            ['value' => $json('general-services.general'), 'is_secret' => false, 'updated_at' => $now, 'created_at' => $now],
        );

        $phraseSets = [
            'automotive.general' => ['автосервис', 'ремонт автомобилей', 'ремонт авто', 'car repair', 'autowerkstatt'],
            'automotive.steering-wheel-upholstery' => ['перетяжка руля', 'перетяжку руля', 'перетяжка автомобильного руля', 'перетяжка автомобильных рулей', 'перетягиваю рули', 'я перетягиваю рули', 'обшить руль кожей', 'реставрация руля', 'рули', 'lenkrad beziehen', 'lenkrad neu beziehen', 'steering wheel upholstery'],
            'repair-finishing-installation.general' => ['ремонт', 'строительство и ремонт', 'ремонт и монтаж', 'отделочные работы', 'мастер по ремонту', 'reparatur und montage', 'repair and installation'],
            'repair-finishing-installation.door-installation' => ['установка дверей', 'устанавливаю двери', 'монтаж дверей', 'поставить входную дверь', 'turen montieren', 'turmontage', 'door installation'],
        ];
        $normalize = static function (string $text): string {
            $text = str_replace('ё', 'е', Str::lower(trim($text)));

            return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
        };

        foreach ($phraseSets as $variationCode => $phrases) {
            $variation = DB::table('business_variations')->where('code', $variationCode)->first();
            if (! $variation) {
                continue;
            }
            DB::table('business_phrases')->where('variation_id', $variation->id)->whereIn('phrase', $phrases)->delete();
            foreach ($phrases as $phrase) {
                $locale = preg_match('/[а-яё]/ui', $phrase) ? 'ru' : (str_contains($phrase, 'tur') || str_contains($phrase, 'lenkrad') || str_contains($phrase, 'autowerkstatt') ? 'de' : 'en');
                DB::table('business_phrases')->updateOrInsert(
                    ['locale' => $locale, 'normalized_phrase' => $normalize($phrase), 'variation_id' => $variation->id],
                    ['category_id' => $variation->category_id, 'phrase' => $phrase, 'weight' => 1, 'enabled' => true, 'updated_at' => $now, 'created_at' => $now],
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'default_request_template_code')->delete();
        $variationId = DB::table('business_variations')->where('code', 'general-services.general')->value('id');
        DB::table('request_templates')->where('code', 'general-services.general')->delete();
        if ($variationId) {
            DB::table('business_phrases')->where('variation_id', $variationId)->delete();
            DB::table('business_variations')->where('id', $variationId)->delete();
        }
        DB::table('business_categories')->where('code', 'general-services')->delete();
    }
};

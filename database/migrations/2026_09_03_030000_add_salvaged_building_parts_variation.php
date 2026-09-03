<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $categoryId = DB::table('business_categories')->where('code', 'purchase')->value('id');
        $template = DB::table('request_templates')->where('code', 'purchase.general')->first();
        if (! $categoryId || ! $template) {
            return;
        }

        $variationId = DB::table('business_variations')->where('code', 'purchase.building-parts')->value('id');
        if (! $variationId) {
            $variationId = DB::table('business_variations')->insertGetId([
                'category_id' => $categoryId,
                'code' => 'purchase.building-parts',
                'name' => json_encode(['de' => 'Alte Fenster & Türen', 'en' => 'Salvaged windows & doors', 'ru' => 'Старые окна и двери', 'uk' => 'Старі вікна та двері'], JSON_UNESCAPED_UNICODE),
                'template_code' => 'purchase.general',
                'enabled' => true,
                'priority' => 85,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $phrases = [
            'de' => ['alte fenster verkaufen', 'alte türen verkaufen', 'wir verkaufen alte fenster und türen', 'gebrauchte fenster und türen', 'historische bauteile verkaufen'],
            'en' => ['sell old windows and doors', 'salvaged building parts'],
            'ru' => ['продаем старые окна и двери', 'старые строительные детали'],
            'uk' => ['продаємо старі вікна та двері', 'старі будівельні деталі'],
        ];
        foreach ($phrases as $locale => $items) {
            foreach ($items as $phrase) {
                DB::table('business_phrases')->updateOrInsert(
                    ['locale' => $locale, 'normalized_phrase' => $phrase, 'variation_id' => $variationId],
                    ['category_id' => $categoryId, 'phrase' => $phrase, 'weight' => 1, 'enabled' => true, 'updated_at' => $now, 'created_at' => $now],
                );
            }
        }

        $configuration = json_decode((string) $template->configuration, true) ?: [];
        $configuration['variation_overrides']['purchase.building-parts'] = [
            'preview' => ['image' => '/brand/service-door.webp', 'primary_color' => '#d8a447', 'secondary_color' => '#111317'],
            'hero' => [
                'eyebrow' => ['de' => 'ALTE BAUTEILE', 'en' => 'SALVAGED BUILDING PARTS', 'ru' => 'СТАРЫЕ СТРОИТЕЛЬНЫЕ ДЕТАЛИ', 'uk' => 'СТАРІ БУДІВЕЛЬНІ ДЕТАЛІ'],
                'title' => ['de' => 'Suchen Sie alte Fenster oder Türen?', 'en' => 'Looking for old windows or doors?', 'ru' => 'Ищете старые окна или двери?', 'uk' => 'Шукаєте старі вікна або двері?'],
                'text' => ['de' => 'Senden Sie Fotos und Maße des gewünschten Bauteils. Wir prüfen, ob passende alte Fenster oder Türen verfügbar sind.', 'en' => 'Send photos and dimensions of the item you need. We will check whether suitable old windows or doors are available.', 'ru' => 'Отправьте фото и размеры нужной детали. Мы проверим, есть ли подходящие старые окна или двери.', 'uk' => 'Надішліть фото та розміри потрібної деталі. Ми перевіримо, чи є відповідні старі вікна або двері.'],
                'subtitle' => ['de' => 'Senden Sie Fotos und Maße des gewünschten Bauteils. Wir prüfen, ob passende alte Fenster oder Türen verfügbar sind.', 'en' => 'Send photos and dimensions of the item you need. We will check whether suitable old windows or doors are available.', 'ru' => 'Отправьте фото и размеры нужной детали. Мы проверим, есть ли подходящие старые окна или двери.', 'uk' => 'Надішліть фото та розміри потрібної деталі. Ми перевіримо, чи є відповідні старі вікна або двері.'],
                'action' => ['de' => 'Anfrage senden', 'en' => 'Send a request', 'ru' => 'Отправить заявку', 'uk' => 'Надіслати запит'],
                'image' => '/brand/service-door.webp',
            ],
            'media' => ['photos_min' => 1, 'photos_max' => 6, 'slots' => [
                ['key' => 'reference', 'role' => 'reference', 'title' => ['de' => 'Gesuchtes Fenster oder Tür', 'en' => 'Window or door needed', 'ru' => 'Нужное окно или дверь', 'uk' => 'Потрібне вікно або двері'], 'instruction' => ['de' => 'Zeigen Sie ein Beispiel oder den Einbauort möglichst vollständig.', 'en' => 'Show an example or the installation location in full.', 'ru' => 'Покажите пример или место установки целиком.', 'uk' => 'Покажіть приклад або місце встановлення повністю.'], 'required' => true],
                ['key' => 'dimensions', 'role' => 'identifier', 'title' => ['de' => 'Maße', 'en' => 'Dimensions', 'ru' => 'Размеры', 'uk' => 'Розміри'], 'instruction' => ['de' => 'Fotografieren Sie die Maße oder eine verständliche Skizze.', 'en' => 'Photograph the measurements or a clear sketch.', 'ru' => 'Сфотографируйте размеры или понятный эскиз.', 'uk' => 'Сфотографуйте розміри або зрозумілий ескіз.'], 'required' => false],
            ]],
        ];
        DB::table('request_templates')->where('id', $template->id)->update([
            'configuration' => json_encode($configuration, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ]);

        $matchingTenantIds = DB::table('tenants')
            ->where('business_description', 'like', '%alte fenster%')
            ->where('business_description', 'like', '%verkauf%')
            ->pluck('id');
        if ($matchingTenantIds->isNotEmpty()) {
            DB::table('tenant_business_profiles')
                ->whereIn('tenant_id', $matchingTenantIds)
                ->update([
                    'category_id' => $categoryId,
                    'variation_id' => $variationId,
                    'request_template_id' => $template->id,
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        $variationId = DB::table('business_variations')->where('code', 'purchase.building-parts')->value('id');
        if ($variationId) {
            $fallbackVariation = DB::table('business_variations')->where('code', 'general-services.general')->first();
            $fallbackTemplateId = DB::table('request_templates')->where('code', 'general-services.general')->value('id');
            if ($fallbackVariation && $fallbackTemplateId) {
                DB::table('tenant_business_profiles')->where('variation_id', $variationId)->update([
                    'category_id' => $fallbackVariation->category_id,
                    'variation_id' => $fallbackVariation->id,
                    'request_template_id' => $fallbackTemplateId,
                    'updated_at' => now(),
                ]);
            }
            DB::table('business_phrases')->where('variation_id', $variationId)->delete();
            DB::table('business_variations')->where('id', $variationId)->delete();
        }

        $template = DB::table('request_templates')->where('code', 'purchase.general')->first();
        if ($template) {
            $configuration = json_decode((string) $template->configuration, true) ?: [];
            unset($configuration['variation_overrides']['purchase.building-parts']);
            DB::table('request_templates')->where('id', $template->id)->update([
                'configuration' => json_encode($configuration, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        }
    }
};

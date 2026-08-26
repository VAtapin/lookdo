<?php

namespace Database\Seeders;

use App\Models\BusinessCategory;
use App\Models\BusinessPhrase;
use App\Models\BusinessVariation;
use App\Models\Plan;
use App\Models\PlatformPage;
use App\Models\RequestTemplate;
use App\Models\SystemSetting;
use App\Services\BusinessClassifier;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['code' => 'start', 'name' => ['de' => 'Start', 'en' => 'Start', 'ru' => 'Старт', 'uk' => 'Старт'], 'description' => ['de' => 'Für Einzelmeister mit visuellen Anfragen oder einfacher Terminbuchung.', 'en' => 'For solo specialists with visual enquiries or simple booking.', 'ru' => 'Для частного мастера с визуальными заявками или простой записью.', 'uk' => 'Для приватного майстра з візуальними заявками або простим записом.'], 'price_monthly' => 19, 'price_yearly' => 190, 'sort_order' => 10, 'entitlements' => ['storage_mb' => '2048', 'request_enabled' => '1', 'booking_enabled' => '1', 'services_enabled' => '1', 'customers_enabled' => '1', 'video_enabled' => '0', 'custom_domain' => '0', 'staff_users' => '1', 'branding_colors' => '1', 'push_enabled' => '1']],
            ['code' => 'pro', 'name' => ['de' => 'Pro', 'en' => 'Pro', 'ru' => 'Профессиональный', 'uk' => 'Професійний'], 'description' => ['de' => 'Domain, Video, Erinnerungen und Kundenbindung für wachsende Betriebe.', 'en' => 'Domain, video, reminders and retention for growing businesses.', 'ru' => 'Домен, видео, напоминания и возврат клиентов для растущего бизнеса.', 'uk' => 'Домен, відео, нагадування та повернення клієнтів для бізнесу, що зростає.'], 'price_monthly' => 39, 'price_yearly' => 390, 'sort_order' => 20, 'badge_text' => ['de' => 'Empfohlen', 'en' => 'Recommended', 'ru' => 'Рекомендуем', 'uk' => 'Рекомендуємо'], 'entitlements' => ['storage_mb' => '10240', 'request_enabled' => '1', 'booking_enabled' => '1', 'services_enabled' => '1', 'customers_enabled' => '1', 'reminders_enabled' => '1', 'before_after_enabled' => '1', 'social_content_enabled' => '1', 'video_enabled' => '1', 'video_max_mb' => '100', 'video_max_seconds' => '45', 'custom_domain' => '1', 'staff_users' => '3', 'branding_colors' => '1', 'platform_branding_removable' => '1', 'push_enabled' => '1', 'telegram_integration' => '1']],
            ['code' => 'business', 'name' => ['de' => 'Business', 'en' => 'Business', 'ru' => 'Бизнес', 'uk' => 'Бізнес'], 'description' => ['de' => 'Für Teams mit KI, erweiterten Kommunikations- und Integrationsfunktionen.', 'en' => 'For teams with AI, advanced communication and integrations.', 'ru' => 'Для команд с AI, расширенными коммуникациями и интеграциями.', 'uk' => 'Для команд з AI, розширеними комунікаціями та інтеграціями.'], 'price_monthly' => 79, 'price_yearly' => 790, 'sort_order' => 30, 'entitlements' => ['storage_mb' => '51200', 'request_enabled' => '1', 'booking_enabled' => '1', 'services_enabled' => '1', 'customers_enabled' => '1', 'reminders_enabled' => '1', 'repeat_visit_enabled' => '1', 'vacancy_fill_enabled' => '1', 'segments_enabled' => '1', 'before_after_enabled' => '1', 'ai_media_enabled' => '1', 'ai_communication_enabled' => '1', 'social_content_enabled' => '1', 'video_enabled' => '1', 'video_max_mb' => '250', 'video_max_seconds' => '90', 'custom_domain' => '1', 'staff_users' => '10', 'branding_colors' => '1', 'platform_branding_removable' => '1', 'push_enabled' => '1', 'telegram_integration' => '1', 'vk_integration' => '1']],
        ];
        foreach ($plans as $data) {
            $entitlements = $data['entitlements'];
            unset($data['entitlements']);
            $plan = Plan::updateOrCreate(['code' => $data['code']], $data + ['currency' => 'EUR']);
            foreach ($entitlements as $key => $value) {
                $plan->entitlements()->updateOrCreate(['key' => $key], ['value' => $value]);
            }
        }

        $categoryDefinitions = [
            'automotive' => [10, ['de' => 'Auto & Fahrzeugservice', 'en' => 'Automotive services', 'ru' => 'Автомобили и автосервис', 'uk' => 'Автомобілі та автосервіс']],
            'repair-finishing-installation' => [20, ['de' => 'Reparatur & Montage', 'en' => 'Repair & installation', 'ru' => 'Ремонт, отделка и монтаж', 'uk' => 'Ремонт, оздоблення та монтаж']],
            'beauty' => [30, ['de' => 'Beauty & Pflege', 'en' => 'Beauty & care', 'ru' => 'Красота и уход', 'uk' => 'Краса та догляд']],
            'appliance-repair' => [40, ['de' => 'Haushaltsgeräte-Reparatur', 'en' => 'Appliance repair', 'ru' => 'Ремонт бытовой техники', 'uk' => 'Ремонт побутової техніки']],
            'furniture' => [50, ['de' => 'Möbel', 'en' => 'Furniture', 'ru' => 'Мебель', 'uk' => 'Меблі']],
            'garden' => [60, ['de' => 'Garten & Grundstück', 'en' => 'Garden & grounds', 'ru' => 'Сад и участок', 'uk' => 'Сад і ділянка']],
            'cleaning' => [70, ['de' => 'Reinigung', 'en' => 'Cleaning', 'ru' => 'Уборка и чистка', 'uk' => 'Прибирання та чищення']],
            'bicycles' => [80, ['de' => 'Fahrräder', 'en' => 'Bicycles', 'ru' => 'Велосипеды', 'uk' => 'Велосипеди']],
            'advertising-signage' => [90, ['de' => 'Werbeschilder & Beschriftung', 'en' => 'Advertising & signage', 'ru' => 'Рекламные вывески и оформление', 'uk' => 'Рекламні вивіски та оформлення']],
            'general-services' => [999, ['de' => 'Allgemeine Dienstleistungen', 'en' => 'General services', 'ru' => 'Другие услуги', 'uk' => 'Інші послуги']],
        ];
        $categories = [];
        foreach ($categoryDefinitions as $code => [$sortOrder, $name]) {
            $categories[$code] = BusinessCategory::updateOrCreate(['code' => $code], ['name' => $name, 'sort_order' => $sortOrder]);
        }

        $variationDefinitions = [
            'automotive.general' => ['automotive', 10, ['de' => 'Allgemeine Fahrzeuganfrage', 'en' => 'General vehicle request', 'ru' => 'Общая заявка по автомобилю', 'uk' => 'Загальна заявка щодо автомобіля']],
            'automotive.steering-wheel-upholstery' => ['automotive', 100, ['de' => 'Lenkrad neu beziehen', 'en' => 'Steering wheel upholstery', 'ru' => 'Перетяжка руля', 'uk' => 'Перетяжка керма']],
            'repair-finishing-installation.general' => ['repair-finishing-installation', 10, ['de' => 'Allgemeine Reparatur oder Montage', 'en' => 'General repair or installation', 'ru' => 'Общий ремонт или монтаж', 'uk' => 'Загальний ремонт або монтаж']],
            'repair-finishing-installation.door-installation' => ['repair-finishing-installation', 90, ['de' => 'Türmontage', 'en' => 'Door installation', 'ru' => 'Установка дверей', 'uk' => 'Встановлення дверей']],
            'beauty.general' => ['beauty', 10, ['de' => 'Allgemeine Beauty-Anfrage', 'en' => 'General beauty enquiry', 'ru' => 'Общая заявка по уходу', 'uk' => 'Загальна заявка з догляду']],
            'beauty.brows' => ['beauty', 100, ['de' => 'Augenbrauen / Brow Artist', 'en' => 'Brows / Brow Artist', 'ru' => 'Брови / Brow Master', 'uk' => 'Брови / Brow Master']],
            'appliance-repair.general' => ['appliance-repair', 20, ['de' => 'Haushaltsgeräte-Reparatur', 'en' => 'Appliance repair', 'ru' => 'Ремонт бытовой техники', 'uk' => 'Ремонт побутової техніки']],
            'furniture.general' => ['furniture', 20, ['de' => 'Möbelarbeiten', 'en' => 'Furniture services', 'ru' => 'Мебель', 'uk' => 'Меблі']],
            'garden.general' => ['garden', 20, ['de' => 'Gartenarbeiten', 'en' => 'Garden services', 'ru' => 'Сад и участок', 'uk' => 'Сад і ділянка']],
            'cleaning.general' => ['cleaning', 20, ['de' => 'Reinigung', 'en' => 'Cleaning', 'ru' => 'Уборка и чистка', 'uk' => 'Прибирання та чищення']],
            'bicycles.general' => ['bicycles', 20, ['de' => 'Fahrradservice', 'en' => 'Bicycle service', 'ru' => 'Велосипеды', 'uk' => 'Велосипеди']],
            'advertising-signage.general' => ['advertising-signage', 20, ['de' => 'Werbeschilder & Beschriftung', 'en' => 'Advertising & signage', 'ru' => 'Рекламные вывески', 'uk' => 'Рекламні вивіски']],
            'general-services.general' => ['general-services', 1, ['de' => 'Universelle Anfrage', 'en' => 'Universal request', 'ru' => 'Универсальная заявка', 'uk' => 'Універсальна заявка']],
        ];
        $variations = [];
        foreach ($variationDefinitions as $code => [$categoryCode, $priority, $name]) {
            $variations[$code] = BusinessVariation::updateOrCreate(['code' => $code], ['category_id' => $categories[$categoryCode]->id, 'name' => $name, 'template_code' => $code, 'priority' => $priority]);
        }

        $requestTemplates = [
            'automotive.general' => ['automotive', 10, true, 4],
            'repair-finishing-installation.general' => ['repair-finishing-installation', 20, true, 5],
            'beauty.general' => ['beauty', 30, false, 4],
            'appliance-repair.general' => ['appliance-repair', 40, true, 5],
            'furniture.general' => ['furniture', 50, true, 5],
            'garden.general' => ['garden', 60, true, 5],
            'cleaning.general' => ['cleaning', 70, true, 5],
            'bicycles.general' => ['bicycles', 80, true, 5],
            'advertising-signage.general' => ['advertising-signage', 90, true, 5],
            'general-services.general' => ['general-services', 999, true, 5],
        ];
        $sourceDocuments = [
            'beauty.general' => 'templates/BASE_REQUEST_TEMPLATE.md',
            'general-services.general' => 'templates/BASE_REQUEST_TEMPLATE.md',
        ];
        foreach ($requestTemplates as $code => [$categoryCode, $sortOrder, $video, $photosMax]) {
            RequestTemplate::updateOrCreate(['code' => $code], [
                'category_id' => $categories[$categoryCode]->id, 'variation_id' => $variations[$code]->id,
                'name' => $variations[$code]->name, 'configuration' => [
                    'engine' => 'request', 'capabilities' => ['request' => true, 'messages' => true, 'push' => true],
                    'media' => ['photos_min' => 1, 'photos_max' => $photosMax, 'video_allowed' => $video],
                    'locales' => ['de', 'en', 'ru', 'uk'], 'source_document' => $sourceDocuments[$code] ?? 'templates/'.str_replace('.', '/', $code).'.md',
                ], 'version' => 1, 'sort_order' => $sortOrder,
            ]);
        }
        $this->seedTemplateFromJson(base_path('templates/automotive/steering-wheel/template.json'), $categories['automotive'], $variations['automotive.steering-wheel-upholstery'], 'automotive.general');
        $this->seedTemplateFromJson(base_path('templates/repair-finishing-installation/door-installation/template.json'), $categories['repair-finishing-installation'], $variations['repair-finishing-installation.door-installation'], 'repair-finishing-installation.general');
        $this->seedTemplateFromJson(base_path('templates/beauty/brows/template.json'), $categories['beauty'], $variations['beauty.brows'], 'beauty.general');

        $automotive = $categories['automotive'];
        $repair = $categories['repair-finishing-installation'];
        $autoGeneral = $variations['automotive.general'];
        $steering = $variations['automotive.steering-wheel-upholstery'];
        $repairGeneral = $variations['repair-finishing-installation.general'];
        $doors = $variations['repair-finishing-installation.door-installation'];
        $classifier = app(BusinessClassifier::class);
        $phraseSets = [
            'automotive.general' => ['ru' => ['автосервис', 'ремонт автомобилей', 'ремонт авто'], 'de' => ['autowerkstatt'], 'en' => ['car repair']],
            'automotive.steering-wheel-upholstery' => ['ru' => ['перетяжка руля', 'перетяжку руля', 'перетяжка автомобильных рулей', 'перетягиваю рули', 'обшить руль кожей', 'реставрация руля', 'ремонт кожи руля'], 'uk' => ['перетяжка керма', 'обшити кермо шкірою'], 'de' => ['lenkrad beziehen', 'lenkrad neu beziehen'], 'en' => ['steering wheel upholstery']],
            'repair-finishing-installation.general' => ['ru' => ['ремонт', 'строительство и ремонт', 'ремонт и монтаж', 'отделочные работы', 'мастер по ремонту'], 'uk' => ['ремонт і монтаж', 'оздоблювальні роботи'], 'de' => ['reparatur und montage'], 'en' => ['repair and installation']],
            'repair-finishing-installation.door-installation' => ['ru' => ['установка дверей', 'устанавливаю двери', 'монтаж дверей', 'поставить входную дверь', 'замена двери'], 'uk' => ['встановлення дверей', 'монтаж дверей', 'замінити двері'], 'de' => ['türen montieren', 'türmontage'], 'en' => ['door installation']],
            'beauty.brows' => ['uk' => ['брови', 'майстер брів', 'бровіст', 'корекція брів', 'фарбування брів', 'оформлення брів', 'моделювання брів'], 'ru' => ['брови', 'мастер по бровям', 'бровист', 'коррекция бровей', 'окрашивание бровей', 'оформление бровей', 'моделирование бровей'], 'de' => ['augenbrauen', 'augenbrauenstyling', 'augenbrauenkorrektur', 'augenbrauen färben', 'brow artist', 'brow stylist'], 'en' => ['brow master', 'brow artist']],
            'appliance-repair.general' => ['ru' => ['ремонт бытовой техники', 'ремонт стиральных машин', 'ремонт холодильников', 'техника не работает'], 'uk' => ['ремонт побутової техніки', 'ремонт пральних машин'], 'de' => ['haushaltsgeräte reparatur'], 'en' => ['appliance repair']],
            'furniture.general' => ['ru' => ['ремонт мебели', 'реставрация мебели', 'перетяжка мебели', 'сборка мебели'], 'uk' => ['ремонт меблів', 'перетяжка меблів'], 'de' => ['möbel reparatur'], 'en' => ['furniture repair']],
            'garden.general' => ['ru' => ['садовые работы', 'уход за садом', 'благоустройство участка', 'садовник'], 'uk' => ['садові роботи', 'догляд за садом'], 'de' => ['gartenarbeiten'], 'en' => ['garden services']],
            'cleaning.general' => ['ru' => ['уборка', 'клининг', 'генеральная уборка', 'уборка после ремонта'], 'uk' => ['прибирання', 'клінінг', 'генеральне прибирання'], 'de' => ['reinigung'], 'en' => ['cleaning service']],
            'bicycles.general' => ['ru' => ['ремонт велосипеда', 'веломастерская', 'велосервис'], 'uk' => ['ремонт велосипеда', 'веломайстерня'], 'de' => ['fahrrad reparatur'], 'en' => ['bicycle repair']],
            'advertising-signage.general' => ['ru' => ['вывески', 'изготовление вывесок', 'наружная реклама', 'оформление витрины'], 'uk' => ['вивіски', 'виготовлення вивісок', 'зовнішня реклама'], 'de' => ['werbeschilder'], 'en' => ['signage installation']],
        ];
        foreach ($phraseSets as $variationCode => $locales) {
            $variation = $variations[$variationCode];
            foreach ($locales as $phraseLocale => $phrases) {
                foreach ($phrases as $phrase) {
                    BusinessPhrase::updateOrCreate(['locale' => $phraseLocale, 'normalized_phrase' => $classifier->normalize($phrase), 'variation_id' => $variation->id], ['category_id' => $variation->category_id, 'phrase' => $phrase, 'weight' => 1]);
                }
            }
        }

        $legacyLegalContent = [
            'de' => 'Dieser Inhalt wird vor dem Produktionsstart vom Betreiber vervollständigt.',
            'en' => 'This content will be completed by the operator before production launch.',
            'ru' => 'Содержимое заполняется владельцем платформы перед рабочим запуском.',
            'uk' => 'Вміст заповнюється власником платформи перед робочим запуском.',
        ];
        foreach (config('legal_pages.pages', []) as $key => $definition) {
            $page = PlatformPage::firstOrNew(['key' => $key]);
            $titles = $page->title ?? [];
            $contents = $page->content ?? [];
            foreach (['de', 'en', 'ru', 'uk'] as $pageLocale) {
                if (blank($titles[$pageLocale] ?? null) || ($titles[$pageLocale] ?? null) === ucfirst($key)) {
                    $titles[$pageLocale] = $definition['title'][$pageLocale];
                }
                if (blank($contents[$pageLocale] ?? null) || ($contents[$pageLocale] ?? null) === $legacyLegalContent[$pageLocale]) {
                    $contents[$pageLocale] = $definition['content'][$pageLocale];
                }
            }
            $page->fill(['title' => $titles, 'content' => $contents, 'is_published' => $page->exists ? $page->is_published : true])->save();
        }
        foreach (['platform_name' => 'LOOKDO', 'default_locale' => 'ru', 'default_request_template_code' => 'general-services.general', 'registration_enabled' => true, 'enabled_locales' => ['de', 'en', 'ru', 'uk'], 'support_email' => 'support@lookdo.app', 'trial_days_default' => 0, 'upload_base_limit_mb' => 100, 'integrations' => ['stripe' => true, 'openai' => true], 'maintenance' => false] as $key => $value) {
            SystemSetting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
        foreach (config('legal_pages.operator_settings', []) as $key => $value) {
            SystemSetting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    private function seedTemplateFromJson(string $path, BusinessCategory $category, BusinessVariation $variation, string $parent): void
    {
        if (! is_file($path)) {
            return;
        }
        $raw = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $configuration = [
            'engine' => ($raw['capabilities']['booking_primary'] ?? false) ? 'booking' : 'request',
            'capabilities' => $raw['capabilities'] ?? ['request' => true],
            'primary_action_label' => $raw['primary_action'] ?? ['ru' => $raw['cta_label'] ?? 'Показать задачу'],
            'title' => ['ru' => $raw['title'] ?? ''], 'intro' => ['ru' => $raw['intro'] ?? ''],
            'media' => $raw['media'] ?? ['slots' => $raw['media_slots'] ?? [], 'video' => $raw['video'] ?? []],
            'fields' => $raw['fields'] ?? [], 'booking' => $raw['booking'] ?? null,
            'submit' => $raw['submit'] ?? ['label' => $raw['submit_label'] ?? 'Отправить мастеру'],
            'success' => $raw['success'] ?? ['title' => $raw['success_title'] ?? 'Готово!', 'text' => $raw['success_text'] ?? ''],
            'push_prompt' => $raw['push_prompt'] ?? [], 'ai_phrases' => $raw['ai_phrases'] ?? [],
            'ai_rules' => $raw['ai_rules'] ?? null, 'locales' => $raw['locales'] ?? ['de', 'en', 'ru', 'uk'],
            'ui_reference' => $raw['ui_reference'] ?? null, 'source_definition' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $path),
        ];
        if ($variation->code === 'beauty.brows') {
            $configuration['ui_reference'] = ['strict' => true, 'path' => 'templates/beauty/brows/UI/mobile-reference.svg'];
            $configuration['preview_palette'] = ['example' => 'pink', 'tenant_configurable' => true, 'semantic_tokens_required' => true];
        }
        RequestTemplate::updateOrCreate(['code' => $variation->template_code], ['category_id' => $category->id, 'variation_id' => $variation->id, 'parent_code' => $parent, 'name' => $variation->name, 'configuration' => $configuration, 'version' => 1, 'sort_order' => $variation->priority]);
    }
}

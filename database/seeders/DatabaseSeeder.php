<?php

namespace Database\Seeders;

use App\Models\BusinessCategory;
use App\Models\BusinessPhrase;
use App\Models\BusinessVariation;
use App\Models\Plan;
use App\Models\PlatformPage;
use App\Models\RequestTemplate;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\BusinessClassifier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['code' => 'start', 'name' => ['de' => 'Start', 'en' => 'Start', 'ru' => 'Старт'], 'description' => ['de' => 'Für Einzelmeister, die Anfragen mit Fotos erhalten möchten.', 'en' => 'For solo specialists receiving visual enquiries.', 'ru' => 'Для частного мастера, принимающего заявки с фотографиями.'], 'price_monthly' => 19, 'price_yearly' => 190, 'sort_order' => 10, 'entitlements' => ['storage_mb' => '2048', 'video_enabled' => '0', 'custom_domain' => '0', 'staff_users' => '1', 'branding_colors' => '1', 'push_enabled' => '1']],
            ['code' => 'pro', 'name' => ['de' => 'Pro', 'en' => 'Pro', 'ru' => 'Профессиональный'], 'description' => ['de' => 'Eigene Domain, Video und mehr Speicher für wachsende Betriebe.', 'en' => 'Custom domain, video and more storage for growing businesses.', 'ru' => 'Собственный домен, видео и больше места для растущего бизнеса.'], 'price_monthly' => 39, 'price_yearly' => 390, 'sort_order' => 20, 'badge_text' => ['de' => 'Empfohlen', 'en' => 'Recommended', 'ru' => 'Рекомендуем'], 'entitlements' => ['storage_mb' => '10240', 'video_enabled' => '1', 'video_max_mb' => '100', 'video_max_seconds' => '45', 'custom_domain' => '1', 'staff_users' => '3', 'branding_colors' => '1', 'platform_branding_removable' => '1', 'push_enabled' => '1', 'telegram_integration' => '1']],
            ['code' => 'business', 'name' => ['de' => 'Business', 'en' => 'Business', 'ru' => 'Бизнес'], 'description' => ['de' => 'Für Teams mit erweiterten Limits und Integrationen.', 'en' => 'For teams requiring higher limits and integrations.', 'ru' => 'Для команд с увеличенными лимитами и интеграциями.'], 'price_monthly' => 79, 'price_yearly' => 790, 'sort_order' => 30, 'entitlements' => ['storage_mb' => '51200', 'video_enabled' => '1', 'video_max_mb' => '250', 'video_max_seconds' => '90', 'custom_domain' => '1', 'staff_users' => '10', 'branding_colors' => '1', 'platform_branding_removable' => '1', 'push_enabled' => '1', 'telegram_integration' => '1', 'vk_integration' => '1']],
        ];
        foreach ($plans as $data) {
            $entitlements = $data['entitlements'];
            unset($data['entitlements']);
            $plan = Plan::updateOrCreate(['code' => $data['code']], $data + ['currency' => 'EUR', 'is_active' => true, 'is_public' => true]);
            foreach ($entitlements as $key => $value) {
                $plan->entitlements()->updateOrCreate(['key' => $key], ['value' => $value]);
            }
        }

        $automotive = BusinessCategory::updateOrCreate(['code' => 'automotive'], ['name' => ['de' => 'Auto & Fahrzeugservice', 'en' => 'Automotive services', 'ru' => 'Автомобили и автосервис'], 'sort_order' => 10]);
        $repair = BusinessCategory::updateOrCreate(['code' => 'repair-finishing-installation'], ['name' => ['de' => 'Reparatur & Montage', 'en' => 'Repair & installation', 'ru' => 'Ремонт, отделка и монтаж'], 'sort_order' => 20]);
        $autoGeneral = BusinessVariation::updateOrCreate(['code' => 'automotive.general'], ['category_id' => $automotive->id, 'name' => ['de' => 'Allgemeine Fahrzeuganfrage', 'en' => 'General vehicle request', 'ru' => 'Общая заявка по автомобилю'], 'template_code' => 'automotive.general', 'priority' => 10]);
        $steering = BusinessVariation::updateOrCreate(['code' => 'automotive.steering-wheel-upholstery'], ['category_id' => $automotive->id, 'name' => ['de' => 'Lenkrad neu beziehen', 'en' => 'Steering wheel upholstery', 'ru' => 'Перетяжка руля'], 'template_code' => 'automotive.steering-wheel-upholstery', 'priority' => 100]);
        $repairGeneral = BusinessVariation::updateOrCreate(['code' => 'repair-finishing-installation.general'], ['category_id' => $repair->id, 'name' => ['de' => 'Allgemeine Reparatur oder Montage', 'en' => 'General repair or installation', 'ru' => 'Общий ремонт или монтаж'], 'template_code' => 'repair-finishing-installation.general', 'priority' => 10]);
        $doors = BusinessVariation::updateOrCreate(['code' => 'repair-finishing-installation.door-installation'], ['category_id' => $repair->id, 'name' => ['de' => 'Türmontage', 'en' => 'Door installation', 'ru' => 'Установка дверей'], 'template_code' => 'repair-finishing-installation.door-installation', 'priority' => 90]);

        RequestTemplate::updateOrCreate(['code' => 'automotive.general'], ['category_id' => $automotive->id, 'variation_id' => $autoGeneral->id, 'name' => $autoGeneral->name, 'configuration' => ['primary_action_label' => ['de' => 'Fahrzeug zeigen', 'en' => 'Show my vehicle', 'ru' => 'Показать автомобиль'], 'media' => ['photos_min' => 1, 'photos_max' => 4, 'video_allowed' => true], 'fields' => []], 'sort_order' => 10]);
        RequestTemplate::updateOrCreate(['code' => 'repair-finishing-installation.general'], ['category_id' => $repair->id, 'variation_id' => $repairGeneral->id, 'name' => $repairGeneral->name, 'configuration' => ['primary_action_label' => ['de' => 'Aufgabe zeigen', 'en' => 'Show the task', 'ru' => 'Показать задачу'], 'media' => ['photos_min' => 1, 'photos_max' => 5, 'video_allowed' => true], 'fields' => []], 'sort_order' => 20]);
        $this->seedTemplateFromJson(base_path('templates/automotive/steering-wheel/template.json'), $automotive, $steering, 'automotive.general');
        $this->seedTemplateFromJson(base_path('templates/repair-finishing-installation/door-installation/template.json'), $repair, $doors, 'repair-finishing-installation.general');

        $classifier = app(BusinessClassifier::class);
        $phraseSets = [
            [$autoGeneral, ['автосервис', 'ремонт автомобилей', 'ремонт авто', 'car repair', 'autowerkstatt']],
            [$steering, ['перетяжка руля', 'перетягиваю рули', 'обшить руль кожей', 'реставрация руля', 'lenkrad beziehen', 'lenkrad neu beziehen', 'steering wheel upholstery']],
            [$repairGeneral, ['ремонт и монтаж', 'отделочные работы', 'мастер по ремонту', 'reparatur und montage', 'repair and installation']],
            [$doors, ['установка дверей', 'устанавливаю двери', 'монтаж дверей', 'поставить входную дверь', 'turen montieren', 'turmontage', 'door installation']],
        ];
        foreach ($phraseSets as [$variation,$phrases]) {
            foreach ($phrases as $phrase) {
                BusinessPhrase::updateOrCreate(['locale' => preg_match('/[а-яё]/ui', $phrase) ? 'ru' : (str_contains($phrase, 'tur') || str_contains($phrase, 'lenkrad') ? 'de' : 'en'), 'normalized_phrase' => $classifier->normalize($phrase), 'variation_id' => $variation->id], ['category_id' => $variation->category_id, 'phrase' => $phrase, 'weight' => 1, 'enabled' => true]);
            }
        }

        foreach (['impressum', 'datenschutz', 'agb', 'widerruf', 'kontakt'] as $key) {
            PlatformPage::updateOrCreate(['key' => $key], ['title' => ['de' => ucfirst($key), 'en' => ucfirst($key), 'ru' => match ($key) {
                'impressum' => 'Выходные данные','datenschutz' => 'Конфиденциальность','agb' => 'Условия использования','widerruf' => 'Отказ от договора','kontakt' => 'Контакты'
            }], 'content' => ['de' => 'Dieser Inhalt wird vor dem Produktionsstart vom Betreiber vervollständigt.', 'en' => 'This content will be completed by the operator before production launch.', 'ru' => 'Содержимое заполняется владельцем платформы перед рабочим запуском.'], 'is_published' => true]);
        }
        foreach (['registration_enabled' => true, 'enabled_locales' => ['de', 'en', 'ru'], 'support_email' => 'support@lookdo.app', 'maintenance' => false] as $key => $value) {
            SystemSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        if (($email = env('SUPER_ADMIN_EMAIL')) && ($password = env('SUPER_ADMIN_PASSWORD'))) {
            User::updateOrCreate(['email' => $email], ['name' => env('SUPER_ADMIN_NAME', 'LOOKDO Admin'), 'password' => Hash::make($password), 'locale' => 'de', 'is_active' => true, 'is_super_admin' => true, 'email_verified_at' => now()]);
        }
    }

    private function seedTemplateFromJson(string $path, BusinessCategory $category, BusinessVariation $variation, string $parent): void
    {
        if (! is_file($path)) {
            return;
        }
        $raw = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $configuration = [
            'primary_action_label' => ['ru' => $raw['cta_label'] ?? 'Показать задачу'], 'title' => ['ru' => $raw['title'] ?? ''], 'intro' => ['ru' => $raw['intro'] ?? ''],
            'media' => $raw['media'] ?? ['slots' => $raw['media_slots'] ?? [], 'video' => $raw['video'] ?? []], 'fields' => $raw['fields'] ?? [],
            'submit' => $raw['submit'] ?? ['label' => $raw['submit_label'] ?? 'Отправить мастеру'],
            'success' => $raw['success'] ?? ['title' => $raw['success_title'] ?? 'Готово!', 'text' => $raw['success_text'] ?? ''],
            'push_prompt' => $raw['push_prompt'] ?? [], 'ai_phrases' => $raw['ai_phrases'] ?? [],
        ];
        RequestTemplate::updateOrCreate(['code' => $variation->template_code], ['category_id' => $category->id, 'variation_id' => $variation->id, 'parent_code' => $parent, 'name' => $variation->name, 'configuration' => $configuration, 'enabled' => true, 'version' => 1, 'sort_order' => $variation->priority]);
    }
}

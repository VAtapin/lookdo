<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tenant = DB::table('tenants')->where('slug', 'ivanna-brows')->first();
        if (! $tenant) {
            return;
        }

        $description = 'Догляд, оформлення, корекція, фарбування та ламінування брів у Темпліні — у студії або з виїздом до клієнта.';
        DB::table('tenants')->where('id', $tenant->id)->update([
            'business_description' => $description,
            'updated_at' => now(),
        ]);

        $profile = DB::table('tenant_profiles')->where('tenant_id', $tenant->id)->first();
        if (! $profile) {
            return;
        }

        $content = json_decode((string) $profile->content, true) ?: [];
        $branding = (array) ($content['branding'] ?? []);
        $branding['services'] = 'Корекція, оформлення, фарбування та ламінування брів. Робота у студії та з виїздом до клієнта.';
        $branding['customers'] = 'Клієнти у Темпліні та околицях, яким потрібен професійний догляд за бровами.';
        $branding['description_translations'] = [
            'uk' => $description,
            'ru' => 'Уход, оформление, коррекция, окрашивание и ламинирование бровей в Темплине — в студии или с выездом к клиенту.',
            'de' => 'Pflege, Formgebung, Korrektur, Färben und Laminierung von Augenbrauen in Templin – im Studio oder mobil bei Kundinnen und Kunden.',
        ];
        $heroCopy = [
            'eyebrow' => ['uk' => 'IVANNA BROWS', 'ru' => 'IVANNA BROWS', 'de' => 'IVANNA BROWS'],
            'title' => [
                'uk' => 'Брови, які підкреслюють вашу красу',
                'ru' => 'Брови, которые подчёркивают вашу красоту',
                'de' => 'Augenbrauen, die Ihre natürliche Schönheit betonen',
            ],
            'text' => [
                'uk' => 'Оберіть послугу та зручний час. Прийом у студії або з виїздом до вас.',
                'ru' => 'Выберите услугу и удобное время. Приём в студии или с выездом к вам.',
                'de' => 'Wählen Sie eine Leistung und einen passenden Termin – im Studio oder mobil bei Ihnen.',
            ],
            'action' => ['uk' => 'Записатися', 'ru' => 'Записаться', 'de' => 'Termin buchen'],
        ];
        $branding['hero_copy'] = $heroCopy;
        $content['branding'] = $branding;
        $appConfiguration = (array) ($content['app_configuration'] ?? []);
        $appConfiguration['hero'] = array_replace((array) ($appConfiguration['hero'] ?? []), $heroCopy);
        $content['app_configuration'] = $appConfiguration;

        DB::table('tenant_profiles')->where('id', $profile->id)->update([
            'content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // This is a tenant-specific content correction; do not restore incorrect copy.
    }
};

<?php

namespace App\Console\Commands;

use App\Models\BusinessCategory;
use App\Models\BusinessPhrase;
use App\Models\BusinessVariation;
use App\Models\Plan;
use App\Models\PlatformPage;
use App\Models\RequestTemplate;
use App\Models\SystemSetting;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;

class PlatformData extends Command
{
    protected $signature = 'lookdo:platform-data {--repair : Restore all required platform records idempotently}';

    protected $description = 'Check or restore LOOKDO plans, taxonomy, templates, dictionary and platform content';

    public function handle(DatabaseSeeder $seeder): int
    {
        if ($this->option('repair')) {
            $seeder->run();
            $this->info('Platform data restored.');
        }

        $required = [
            'plans' => [Plan::class, ['start', 'pro', 'business']],
            'categories' => [BusinessCategory::class, ['automotive', 'repair-finishing-installation', 'beauty', 'appliance-repair', 'furniture', 'garden', 'cleaning', 'bicycles', 'advertising-signage', 'purchase', 'general-services']],
            'variations' => [BusinessVariation::class, ['automotive.general', 'automotive.steering-wheel-upholstery', 'repair-finishing-installation.general', 'repair-finishing-installation.door-installation', 'beauty.general', 'beauty.brows', 'appliance-repair.general', 'furniture.general', 'garden.general', 'cleaning.general', 'bicycles.general', 'advertising-signage.general', 'purchase.books', 'purchase.vehicles', 'purchase.antiques', 'general-services.general']],
            'templates' => [RequestTemplate::class, ['automotive.general', 'automotive.steering-wheel-upholstery', 'repair-finishing-installation.general', 'repair-finishing-installation.door-installation', 'beauty.general', 'beauty.brows', 'appliance-repair.general', 'furniture.general', 'garden.general', 'cleaning.general', 'bicycles.general', 'advertising-signage.general', 'purchase.general', 'general-services.general']],
        ];
        $missing = [];
        foreach ($required as $label => [$model, $codes]) {
            $found = $model::query()->whereIn('code', $codes)->pluck('code')->all();
            foreach (array_diff($codes, $found) as $code) {
                $missing[] = $label.':'.$code;
            }
        }

        $rows = [
            ['Tarife', Plan::count(), Plan::where('is_active', true)->count()],
            ['Kategorien', BusinessCategory::count(), BusinessCategory::where('enabled', true)->count()],
            ['Varianten', BusinessVariation::count(), BusinessVariation::where('enabled', true)->count()],
            ['Vorlagen', RequestTemplate::count(), RequestTemplate::where('enabled', true)->count()],
            ['KI-Wörterbuch', BusinessPhrase::count(), BusinessPhrase::where('enabled', true)->count()],
            ['Inhaltsseiten', PlatformPage::count(), PlatformPage::where('is_published', true)->count()],
        ];
        $this->table(['Bereich', 'Gesamt', 'Aktiv'], $rows);
        $this->line('Standardvorlage: '.SystemSetting::read('default_request_template_code', 'FEHLT'));

        if ($missing !== []) {
            $this->error('Fehlende Pflichtdaten: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $this->info('Pflichtdaten vollständig.');

        return self::SUCCESS;
    }
}

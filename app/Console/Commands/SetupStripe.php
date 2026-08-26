<?php

namespace App\Console\Commands;

use App\Services\StripeService;
use Illuminate\Console\Command;
use Throwable;

class SetupStripe extends Command
{
    protected $signature = 'lookdo:stripe:setup {--sync-plans : Explicitly synchronize active plans}';

    protected $description = 'Test Stripe and optionally synchronize plans without changing external services or .env';

    public function handle(StripeService $stripe): int
    {
        try {
            $account = $stripe->testConnection();
            $this->info('Stripe connected: '.$account['id'].' ('.($account['livemode'] ? 'live' : 'test').').');
            if ($this->option('sync-plans')) {
                $this->info('Plans synchronized: '.$stripe->syncAllPlans().'.');
            }
            if (filled(config('services.stripe.webhook_secret'))) {
                $this->info('Webhook secret is already configured.');
            } else {
                $this->warn('STRIPE_WEBHOOK_SECRET is empty. Configure the Stripe webhook and .env manually.');
                $this->line('Endpoint URL: '.rtrim((string) config('app.url'), '/').'/api/stripe/webhook');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

}

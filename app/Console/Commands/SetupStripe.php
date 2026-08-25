<?php

namespace App\Console\Commands;

use App\Services\StripeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class SetupStripe extends Command
{
    protected $signature = 'lookdo:stripe:setup {--skip-plans : Do not synchronize plans} {--new-webhook : Replace an already configured webhook secret}';

    protected $description = 'Test Stripe, synchronize plans, create the webhook and save its signing secret';

    public function handle(StripeService $stripe): int
    {
        try {
            $account = $stripe->testConnection();
            $this->info('Stripe connected: '.$account['id'].' ('.($account['livemode'] ? 'live' : 'test').').');
            if (! $this->option('skip-plans')) {
                $this->info('Plans synchronized: '.$stripe->syncAllPlans().'.');
            }
            if (filled(config('services.stripe.webhook_secret')) && ! $this->option('new-webhook')) {
                $this->info('Webhook secret is already configured.');

                return self::SUCCESS;
            }
            $secret = $stripe->createWebhookEndpoint(rtrim((string) config('app.url'), '/').'/api/stripe/webhook');
            $this->writeEnvironmentValue('STRIPE_WEBHOOK_SECRET', $secret);
            config(['services.stripe.webhook_secret' => $secret]);
            $this->info('Stripe webhook created and STRIPE_WEBHOOK_SECRET saved to .env.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function writeEnvironmentValue(string $key, string $value): void
    {
        $path = base_path('.env');
        if (! File::exists($path)) {
            throw new RuntimeException('.env does not exist.');
        }
        $contents = File::get($path);
        $line = $key.'='.$value;
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
        $contents = preg_match($pattern, $contents) ? preg_replace($pattern, $line, $contents) : rtrim($contents).PHP_EOL.$line.PHP_EOL;
        File::put($path, $contents);
    }
}

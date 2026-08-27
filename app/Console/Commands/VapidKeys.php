<?php

namespace App\Console\Commands;

use App\Services\TenantWebPushService;
use Illuminate\Console\Command;
use JsonException;
use Minishlink\WebPush\VAPID;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class VapidKeys extends Command
{
    protected $signature = 'lookdo:webpush:keys {--check : Check the configured keys without generating new ones}';

    protected $description = 'Generate VAPID keys for manual .env setup or verify the current Web Push configuration';

    public function handle(TenantWebPushService $webPush): int
    {
        if ($this->option('check')) {
            if (! $webPush->configured()) {
                $this->error('Web Push is not configured. VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY and VAPID_SUBJECT are required.');

                return self::FAILURE;
            }

            $this->info('Web Push configuration is complete.');
            $this->line('VAPID_SUBJECT='.(string) config('services.webpush.subject'));

            return self::SUCCESS;
        }

        try {
            $keys = $this->generateKeys();
            $this->warn('Paste these values into .env in the Plesk editor. Do not regenerate them after clients subscribe.');
            $this->newLine();
            $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
            $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
            $this->line('VAPID_SUBJECT='.(string) config('services.webpush.subject', 'mailto:support@lookdo.app'));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array{publicKey:string,privateKey:string}
     *
     * @throws JsonException
     */
    private function generateKeys(): array
    {
        try {
            return VAPID::createVapidKeys();
        } catch (Throwable $phpFailure) {
            $script = <<<'JS'
const crypto = require('node:crypto');
const ecdh = crypto.createECDH('prime256v1');
ecdh.generateKeys();
process.stdout.write(JSON.stringify({
  publicKey: ecdh.getPublicKey(null, 'uncompressed').toString('base64url'),
  privateKey: ecdh.getPrivateKey().toString('base64url')
}));
JS;
            $process = new Process(['node', '-e', $script]);
            $process->setTimeout(15);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('VAPID key generation failed in PHP and Node.js: '.trim($process->getErrorOutput()), 0, $phpFailure);
            }
            $keys = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($keys) || ! is_string($keys['publicKey'] ?? null) || ! is_string($keys['privateKey'] ?? null)) {
                throw new RuntimeException('Node.js returned an invalid VAPID key pair.', 0, $phpFailure);
            }

            return ['publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']];
        }
    }
}

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
    protected $signature = 'lookdo:webpush:keys
        {--check : Check the configured keys without generating new ones}
        {--write : Write the generated pair directly to the application .env file}
        {--force : Allow --write to replace an existing pair}';

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
            if ($this->option('write')) {
                if (! $this->option('force') && (filled(env('VAPID_PUBLIC_KEY')) || filled(env('VAPID_PRIVATE_KEY')))) {
                    $this->error('VAPID keys already exist. Use --force only when the configured pair is broken.');

                    return self::FAILURE;
                }
                $this->writeEnvironment($keys);
                $this->info('A new valid VAPID key pair was written to .env. Existing browser subscriptions must subscribe again.');
                $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);

                return self::SUCCESS;
            }

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

    /** @param array{publicKey:string,privateKey:string} $keys */
    private function writeEnvironment(array $keys): void
    {
        $path = base_path('.env');
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException('The application .env file could not be read.');
        }
        $environment = ['VAPID_PUBLIC_KEY' => $keys['publicKey'], 'VAPID_PRIVATE_KEY' => $keys['privateKey']];
        if (! filled(env('VAPID_SUBJECT'))) {
            $environment['VAPID_SUBJECT'] = (string) config('services.webpush.subject', 'mailto:support@lookdo.app');
        }
        foreach ($environment as $name => $value) {
            $line = $name.'='.$value;
            if (preg_match('/^'.preg_quote($name, '/').'=.*$/m', $contents)) {
                $contents = (string) preg_replace('/^'.preg_quote($name, '/').'=.*$/m', $line, $contents, 1);
            } else {
                $contents = rtrim($contents).PHP_EOL.$line.PHP_EOL;
            }
        }
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('The application .env file could not be updated.');
        }
    }
}

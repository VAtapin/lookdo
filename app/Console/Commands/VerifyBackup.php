<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class VerifyBackup extends Command
{
    protected $signature = 'backup:verify {name? : Backup name without extension}';

    protected $description = 'Verify checksums of a backup (latest by default)';

    public function handle(BackupService $backups): int
    {
        $result = $backups->verify($this->argument('name'));
        if (! $result['valid']) {
            $this->error(implode('; ', $result['errors']));

            return self::FAILURE;
        }
        $this->info('Backup verified: '.$result['name']);

        return self::SUCCESS;
    }
}

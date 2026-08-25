<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class CreateBackup extends Command
{
    protected $signature = 'backup:create';

    protected $description = 'Create a database and application storage backup';

    public function handle(BackupService $backups): int
    {
        $manifest = $backups->create();
        $this->info('Backup created: '.$manifest['name']);

        return self::SUCCESS;
    }
}

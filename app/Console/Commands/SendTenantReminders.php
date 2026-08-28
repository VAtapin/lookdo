<?php

namespace App\Console\Commands;

use App\Services\TenantReminderDispatcher;
use Illuminate\Console\Command;

class SendTenantReminders extends Command
{
    protected $signature = 'lookdo:reminders:send {--limit=100 : Maximum due reminders to process}';

    protected $description = 'Send due LOOKDO tenant reminders through configured channels';

    public function handle(TenantReminderDispatcher $dispatcher): int
    {
        $result = $dispatcher->dispatchDue((int) $this->option('limit'));
        $this->components->info(sprintf(
            'Reminders: %d processed, %d sent, %d queued, %d manual, %d skipped, %d failed.',
            $result['processed'],
            $result['sent'],
            $result['queued'],
            $result['manual'],
            $result['skipped'],
            $result['failed'],
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

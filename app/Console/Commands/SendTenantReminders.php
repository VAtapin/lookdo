<?php

namespace App\Console\Commands;

use App\Jobs\RecordQueueHeartbeat;
use App\Models\SystemSetting;
use App\Services\TenantReminderDispatcher;
use Illuminate\Console\Command;

class SendTenantReminders extends Command
{
    protected $signature = 'lookdo:reminders:send {--limit=100 : Maximum due reminders to process}';

    protected $description = 'Send due LOOKDO tenant reminders through configured channels';

    public function handle(TenantReminderDispatcher $dispatcher): int
    {
        $startedAt = now();
        SystemSetting::query()->updateOrCreate(
            ['key' => 'reminder_dispatch_status'],
            ['value' => ['last_started_at' => $startedAt->toIso8601String()], 'is_secret' => false],
        );
        $result = $dispatcher->dispatchDue((int) $this->option('limit'));
        SystemSetting::query()->updateOrCreate(
            ['key' => 'reminder_dispatch_status'],
            ['value' => [
                'last_started_at' => $startedAt->toIso8601String(),
                'last_finished_at' => now()->toIso8601String(),
                'last_result' => $result,
            ], 'is_secret' => false],
        );
        RecordQueueHeartbeat::dispatch();
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

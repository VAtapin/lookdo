<?php

namespace App\Jobs;

use App\Models\SystemSetting;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecordQueueHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function handle(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => 'queue_worker_heartbeat'],
            ['value' => ['last_run_at' => now()->toIso8601String()], 'is_secret' => false],
        );
    }
}

<?php

namespace App\Observers;

use App\Jobs\SendTenantMessagePush;
use App\Models\TenantMessage;

class TenantMessageObserver
{
    public function created(TenantMessage $message): void
    {
        if ($message->sender_type !== 'master' || ! $message->customer_id) {
            return;
        }

        SendTenantMessagePush::dispatch($message->id)->afterCommit();
    }
}

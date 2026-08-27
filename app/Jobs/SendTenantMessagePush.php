<?php

namespace App\Jobs;

use App\Models\TenantMessage;
use App\Services\TenantWebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendTenantMessagePush implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 45;

    public function __construct(public readonly int $tenantMessageId) {}

    public function handle(TenantWebPushService $webPush): void
    {
        $message = TenantMessage::with(['tenant', 'customer'])->find($this->tenantMessageId);
        if (! $message || $message->sender_type !== 'master' || ! $message->customer || ! $message->tenant?->hasActiveSubscription()) {
            return;
        }

        $locale = in_array($message->customer->locale, ['de', 'en', 'ru', 'uk'], true) ? $message->customer->locale : 'de';
        $actions = ['de' => 'Öffnen', 'en' => 'Open', 'ru' => 'Открыть', 'uk' => 'Відкрити'];

        $webPush->sendToCustomer($message->customer, [
            'title' => $message->tenant->name,
            'body' => $message->body,
            'url' => '/activity',
            'tag' => 'lookdo-message-'.$message->id,
            'action' => $actions[$locale],
        ]);
    }
}

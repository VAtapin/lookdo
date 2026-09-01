<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConfirmAccountEmailChange extends Notification
{
    use Queueable;

    public function __construct(private readonly string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('LOOKDO — E-Mail-Adresse bestätigen')
            ->line('Bestätigen Sie die neue E-Mail-Adresse für Ihr LOOKDO-Konto.')
            ->action('E-Mail-Adresse bestätigen', $this->url)
            ->line('Wenn Sie diese Änderung nicht angefordert haben, ignorieren Sie diese Nachricht.');
    }
}

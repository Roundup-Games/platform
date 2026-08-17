<?php

namespace Tests\Feature\Services\Fixtures;

use App\Dto\PushPayload;
use App\Notifications\BaseNotification;
use Illuminate\Notifications\Messages\MailMessage;

class TestBaseNotification extends BaseNotification
{
    public function __construct(public array $payload = []) {}

    public function toDatabase(object $notifiable): array
    {
        return $this->payload;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->line('Test base notification');
    }

    public function toPush(object $notifiable): PushPayload
    {
        return new PushPayload(
            title: 'Test',
            body: 'Test push',
            icon: '/icon.png',
            url: '/test',
            tag: 'test',
        );
    }
}

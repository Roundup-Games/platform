<?php

namespace App\Listeners;

use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Observes per-channel notification delivery outcomes.
 *
 * NotificationService logs *dispatch* (enqueue) intent, but a queued
 * notification's per-channel send() runs later on the queue worker. Laravel
 * fires NotificationSent / NotificationFailed per channel after that send(),
 * carrying the actual outcome. This listener turns those events into the
 * structured delivery record that was previously missing — covering mail and
 * the database channel (which had zero observability) uniformly alongside
 * push and discord.
 *
 * Safety contract: an observability listener must NEVER break delivery. Every
 * branch is wrapped so a logging failure is swallowed, never re-thrown (which
 * would fail the channel job that just succeeded).
 */
class LogNotificationDelivery
{
    /**
     * Known Laravel/custom channel classes mapped to short log names. Unknown
     * channels fall back to the class basename so new channels are still
     * attributable without an explicit entry here.
     */
    private const CHANNEL_ALIASES = [
        'Illuminate\Notifications\Channels\DatabaseChannel' => 'database',
        'Illuminate\Notifications\Channels\MailChannel' => 'mail',
        'App\Notifications\Channels\PushChannel' => 'push',
        'App\Notifications\Channels\DiscordChannel' => 'discord',
    ];

    public function handle(NotificationSent|NotificationFailed $event): void
    {
        try {
            $notifiable = $event->notifiable;
            $notificationType = $event->notification::class;
            $channel = $this->channelAlias($event->channel);
            $notifiableId = method_exists($notifiable, 'getKey') ? $notifiable->getKey() : null;

            if ($event instanceof NotificationSent) {
                Log::info('notification.channel_delivered', [
                    'notifiable_id' => $notifiableId,
                    'notification_type' => $notificationType,
                    'channel' => $channel,
                    'response' => $this->responseSummary($event->response),
                ]);
            } else {
                // NotificationFailed carries the exception in its $data array
                // (['exception' => Throwable]) on this framework version.
                $exception = $event->data['exception'] ?? null;
                Log::warning('notification.channel_failed', [
                    'notifiable_id' => $notifiableId,
                    'notification_type' => $notificationType,
                    'channel' => $channel,
                    'error' => $exception instanceof Throwable ? $exception->getMessage() : null,
                    'exception' => $exception instanceof Throwable ? $exception::class : null,
                ]);
            }
        } catch (Throwable $e) {
            // Never let a logging failure fail the surrounding channel job.
            Log::warning('notification.delivery_log_failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function channelAlias(string $channel): string
    {
        return self::CHANNEL_ALIASES[$channel] ?? Str::afterLast($channel, '\\');
    }

    /**
     * Reduce the channel response to a short, log-safe summary. Some channels
     * return large objects/HTTP responses; we only want the type for
     * correlation, never a payload that could leak content or secrets.
     */
    private function responseSummary(mixed $response): ?string
    {
        if ($response === null) {
            return null;
        }

        if (is_scalar($response)) {
            return (string) $response;
        }

        return $response::class;
    }
}

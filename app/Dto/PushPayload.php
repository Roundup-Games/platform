<?php

namespace App\Dto;

use InvalidArgumentException;

/**
 * Data transfer object for web push notification payloads.
 *
 * Encapsulates the data that gets JSON-encoded and sent to the
 * browser's Push API via the service worker.
 */
class PushPayload
{
    /**
     * @param  string|null  $tag  Replacement key: a new push with the same
     *                            tag replaces the previous notification on supporting clients.
     * @param  array<int, array{label: string, url: string, action?: string}>|null  $actions
     *                                                                                        Action buttons rendered by the service worker. URL-only buttons
     *                                                                                        ({label, url}) are the wire contract the SW consumes — the SW
     *                                                                                        assigns its own positional `action-N` identifiers and resolves
     *                                                                                        URLs from them; an optional `action` key may ride along for
     *                                                                                        future SW-side dispatch but no current consumer reads it.
     * @param  int|null  $timestamp  Event timestamp in epoch SECONDS (the Web
     *                               Push display timestamp: supporting browsers show "sent at" time
     *                               instead of arrival time).
     * @param  string|null  $badge  Monochrome badge icon path for the Android
     *                              system tray (Notification.badge).
     * @param  bool  $renotify  Re-alert the user when this payload replaces a
     *                          same-tag notification. Requires a non-empty $tag — enforced HERE
     *                          at construction, never in toArray(): both values arrive together
     *                          via named arguments, while PushChannel json_encodes toArray()
     *                          outside its try/flush and the push-test command logs toArray(),
     *                          so a render-time throw would detonate mid-delivery. Construction
     *                          is the loud-but-safe failure point.
     */
    public function __construct(
        public string $title,
        public string $body,
        public string $icon,
        public string $url,
        public ?string $tag = null,
        public ?array $actions = null,
        public ?int $timestamp = null,
        public ?string $badge = null,
        public bool $renotify = false,
    ) {
        if ($renotify && ($tag === null || $tag === '')) {
            throw new InvalidArgumentException(
                'PushPayload: renotify=true requires a non-empty tag — a re-notifying notification must replace an existing one.'
            );
        }

        // Actions are the other half of the wire contract — validated here for
        // the same reason as the renotify⇒tag rule: construction is the loud
        // failure point. Every entry must be an array with non-empty string
        // 'label' and 'url' keys (the app's action dictionary — the service
        // worker maps them to the platform's {action, title} shape), and
        // browsers render at most two actions — a third is a producer bug
        // the platform would silently truncate.
        if ($actions !== null) {
            if (count($actions) > 2) {
                throw new InvalidArgumentException(
                    'PushPayload: at most 2 actions are allowed — platforms render two and silently drop the rest.'
                );
            }

            foreach ($actions as $action) {
                self::assertValidAction($action);
            }
        }
    }

    /**
     * Runtime guard for one action entry. Takes mixed deliberately: the
     * constructor PHPDoc types $actions, but PHPDoc is not a runtime
     * guarantee for producers that skip static analysis — a 'title'-
     * instead-of-'label' typo or a non-string must fail loudly at
     * construction, not degrade silently in the SW render layer.
     */
    private static function assertValidAction(mixed $action): void
    {
        if (! is_array($action)
            || ! isset($action['label'], $action['url'])
            || ! is_string($action['label']) || $action['label'] === ''
            || ! is_string($action['url']) || $action['url'] === ''
        ) {
            throw new InvalidArgumentException(
                'PushPayload: every action needs non-empty string \'label\' and \'url\' keys (the SW maps them to the platform action dictionary).'
            );
        }
    }

    /**
     * Convert to the array format expected by the browser Push API.
     *
     * Optional richness fields ride only when set, so pre-existing
     * payloads serialize to a byte-identical legacy shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'title' => $this->title,
            'body' => $this->body,
            'icon' => $this->icon,
            'url' => $this->url,
        ];

        if ($this->tag !== null) {
            $data['tag'] = $this->tag;
        }

        if ($this->actions !== null && $this->actions !== []) {
            $data['actions'] = $this->actions;
        }

        if ($this->timestamp !== null) {
            $data['timestamp'] = $this->timestamp;
        }

        if ($this->badge !== null) {
            $data['badge'] = $this->badge;
        }

        if ($this->renotify) {
            $data['renotify'] = true;
        }

        return $data;
    }
}

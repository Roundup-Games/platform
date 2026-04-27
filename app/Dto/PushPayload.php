<?php

namespace App\Dto;

/**
 * Data transfer object for web push notification payloads.
 *
 * Encapsulates the data that gets JSON-encoded and sent to the
 * browser's Push API via the service worker.
 */
class PushPayload
{
    public function __construct(
        public string $title,
        public string $body,
        public string $icon,
        public string $url,
        public ?string $tag = null,
    ) {}

    /**
     * Convert to the array format expected by the browser Push API.
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

        return $data;
    }
}

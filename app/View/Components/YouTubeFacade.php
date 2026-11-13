<?php

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Click-to-load YouTube facade (CWV 2026-11).
 *
 * The previous flow rendered the embed iframe directly: bringing it into
 * view pulled ~855KB of YouTube player JS that starved the page's critical
 * path — the game-system-ttrpg template measured 57-59 mobile until this.
 * The facade ships only a poster + play button (~1KB); the delegated
 * handler in resources/js/video-facade.js swaps in the youtube-nocookie
 * iframe on interaction. Nothing third-party loads until the visitor
 * explicitly clicks — which also keeps the Google contact consent-shaped.
 *
 * Graceful degradation: an unparseable stored URL renders a plain outbound
 * link instead, never a broken embed.
 */
class YouTubeFacade extends Component
{
    public string $videoId = '';

    /** Benign query params retained on the embed (e.g. `si` session id) */
    public string $embedParams = '';

    public string $posterUrl = '';

    public function __construct(
        public string $url,
        public string $title = 'Video',
    ) {
        $parsed = self::parseYouTubeUrl($url);

        if ($parsed !== null) {
            $this->videoId = $parsed['id'];
            $this->embedParams = $parsed['params'];
            $this->posterUrl = 'https://i.ytimg.com/vi/'.$parsed['id'].'/hqdefault.jpg';
        }
    }

    /**
     * Extract the video id (and non-identifying query params) from any
     * YouTube URL shape: watch?v=, youtu.be/, /embed/, /shorts/, m.youtube.
     *
     * @return array{id: non-empty-string, params: string}|null Null when the URL is not a recognizable YouTube link.
     */
    public static function parseYouTubeUrl(string $url): ?array
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['host'], $parts['path'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        if (! in_array($host, ['youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com', 'youtu.be'], true)) {
            return null;
        }

        $videoId = null;

        if ($host === 'youtu.be') {
            $videoId = ltrim($parts['path'], '/');
        } else {
            $segments = array_values(array_filter(explode('/', trim($parts['path'], '/')), fn (string $s): bool => $s !== ''));
            $embeddable = in_array($segments[0] ?? '', ['embed', 'shorts', 'v', 'live'], true);

            if ($embeddable && isset($segments[1])) {
                $videoId = $segments[1];
            } elseif (($segments[0] ?? '') === 'watch' && isset($parts['query'])) {
                parse_str($parts['query'], $query);
                $videoId = is_string($query['v'] ?? null) && $query['v'] !== '' ? $query['v'] : null;
            }
        }

        if ($videoId === null || $videoId === '' || preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId) !== 1) {
            return null;
        }

        // Retain benign params (si, t, start, …) — drop the watch-page `v`.
        $params = [];
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
            unset($query['v']);
            $params = $query;
        }

        return [
            'id' => $videoId,
            'params' => $params === [] ? '' : http_build_query($params),
        ];
    }

    public function render(): View
    {
        return view('components.youtube-facade');
    }
}

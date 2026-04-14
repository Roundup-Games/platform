<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Team;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    public function index(): Response
    {
        $xml = Cache::remember('sitemap', 3600, function () {
            $urls = [];

            // Static pages for both locales
            $staticPaths = ['/', '/about', '/contact'];

            foreach (['en', 'de'] as $locale) {
                foreach ($staticPaths as $path) {
                    $urls[] = url($locale . $path);
                }
            }

            // Dynamic: published public events
            $events = Event::public()
                ->select('slug')
                ->get();

            foreach ($events as $event) {
                foreach (['en', 'de'] as $locale) {
                    $urls[] = url($locale . '/events/' . $event->slug);
                }
            }

            // Dynamic: active teams
            $teams = Team::where('is_active', true)
                ->select('slug')
                ->get();

            foreach ($teams as $team) {
                foreach (['en', 'de'] as $locale) {
                    $urls[] = url($locale . '/teams/' . $team->slug);
                }
            }

            return $this->buildXml($urls);
        });

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }

    private function buildXml(array $urls): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        foreach ($urls as $url) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>' . htmlspecialchars($url, ENT_XML1, 'UTF-8') . '</loc>';
            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines);
    }
}

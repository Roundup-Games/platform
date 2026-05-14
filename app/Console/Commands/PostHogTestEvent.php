<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PostHog\PostHog;

class PostHogTestEvent extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'posthog:test-event {--type=server : Event type prefix (server|php)}';

    /**
     * The console command description.
     */
    protected $description = 'Send a test event to PostHog to verify SDK connectivity';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $apiKey = config('posthog.api_key');
        $host = config('posthog.host', 'https://eu.i.posthog.com');

        if (! $apiKey) {
            $this->error('POSTHOG_API_KEY is not configured. Add it to your .env file.');

            return self::FAILURE;
        }

        if (! config('posthog.enabled', true)) {
            $this->error('PostHog is disabled (POSTHOG_ENABLED=false).');

            return self::FAILURE;
        }

        $type = $this->option('type');

        try {
            PostHog::capture([
                'distinctId' => 'test-server-' . gethostname(),
                'event' => "{$type}_test_event",
                'properties' => [
                    'source' => 'artisan-command',
                    'hostname' => gethostname(),
                    'environment' => app()->environment(),
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);

            $this->info("✓ Test event '{$type}_test_event' captured successfully.");
            $this->info("  Host: {$host}");
            $this->info("  API Key: " . 'phc_***...' . substr($apiKey, -4));
            $this->newLine();
            $this->info('Check your PostHog dashboard for the event. It may take a few seconds to appear.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to capture test event: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}

<?php

namespace App\Console\Dev;

use App\Services\PostHogClient;
use Illuminate\Console\Command;

class PostHogTestEvent extends Command
{
    public function __construct(private PostHogClient $posthogClient)
    {
        parent::__construct();
    }

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
        $host = is_string($h = config('posthog.host', 'https://eu.i.posthog.com')) ? $h : 'https://eu.i.posthog.com';

        if (! $apiKey) {
            $this->error('POSTHOG_API_KEY is not configured. Add it to your .env file.');

            return self::FAILURE;
        }

        if (! config('posthog.enabled', true)) {
            $this->error('PostHog is disabled (POSTHOG_ENABLED=false).');

            return self::FAILURE;
        }

        // option() is array|bool|float|int|string|null at the stub level (a
        // repeated --type=a --type=b arrives as array); narrow to the string
        // the interpolation at the capture/info lines requires, falling back
        // to the signature default for anything else.
        $type = $this->option('type');
        $type = is_string($type) ? $type : 'server';

        try {
            $this->posthogClient->capture([
                'distinctId' => 'test-server-'.gethostname(),
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
            $this->info('  API Key: '.'phc_***...'.substr(is_string($apiKey) ? $apiKey : '', -4));
            $this->newLine();
            $this->info('Check your PostHog dashboard for the event. It may take a few seconds to appear.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to capture test event: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}

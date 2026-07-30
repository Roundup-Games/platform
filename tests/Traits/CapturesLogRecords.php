<?php

namespace Tests\Traits;

use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

/**
 * Capture real log records via a Monolog TestHandler.
 *
 * Why this exists: the two obvious Log-facade capture mechanisms are unreliable
 * in this harness. `Log::listen()` relies on the MessageLogged event being
 * dispatched by the default driver's event dispatcher — which does not happen
 * with single-driver channels like the test env's `stderr`, so it silently
 * captures zero records. `Log::spy()` + `shouldNotHaveReceived('info')` counts
 * EVERY info() call, including unrelated observer/job noise fired during test
 * setup (e.g. the Discord publish observer logs on Game::factory()->create()).
 *
 * The TestHandler attaches at the Monolog layer, so it is channel-agnostic and
 * captures the full record (message, level, context). Records are normalized
 * to a plain array shape so test code stays decoupled from Monolog's LogRecord
 * DTO (which differs between Monolog 2 and 3). Assert on specific messages
 * rather than counting calls.
 *
 * Normalized shape: ['message' => string, 'level_name' => string, 'context' => array]
 */
trait CapturesLogRecords
{
    protected TestHandler $logHandler;

    /**
     * Replace the default driver's handlers with a capture-only TestHandler.
     * Call BEFORE the code under test runs.
     */
    protected function captureLogRecords(): TestHandler
    {
        $this->logHandler = new TestHandler;

        $logger = Log::driver()->getLogger();
        if ($logger instanceof Logger) {
            $logger->setHandlers([$this->logHandler]);
        }

        return $this->logHandler;
    }

    /**
     * All captured records, normalized to a stable plain-array shape.
     *
     * @return list<array{message: string, level_name: string, context: array<string, mixed>}>
     */
    protected function logRecords(): array
    {
        return array_map(
            static fn ($r): array => [
                'message' => $r['message'],
                'level_name' => $r['level_name'],
                'context' => $r['context'] ?? [],
            ],
            $this->logHandler->getRecords(),
        );
    }

    /**
     * The first record whose message matches exactly, or null.
     *
     * @return array{message: string, level_name: string, context: array<string, mixed>}|null
     */
    protected function logRecord(string $message): ?array
    {
        foreach ($this->logRecords() as $record) {
            if ($record['message'] === $message) {
                return $record;
            }
        }

        return null;
    }
}

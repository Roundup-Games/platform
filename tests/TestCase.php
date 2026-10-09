<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Sleep;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        URL::defaults(['locale' => 'en']);

        /*
         * Suite-level guards (testing best practices, "Global Fakes"):
         * - preventStrayRequests: any outbound request through Laravel's HTTP
         *   client without a matching Http::fake([...]) fails the test instead
         *   of reaching the network. Tests faking an endpoint must use
         *   Http::fake([endpoint => response]) — a bare Http::fake() hides
         *   which endpoint the code under test actually calls.
         * - Sleep::fake(syncWithCarbon: true): retry/backoff paths must not
         *   really sleep; sleeps still advance Carbon so time-based assertions
         *   stay consistent.
         * - Exceptions::fake: exceptions are never reported to external
         *   services; assert with Exceptions::assertReported(). Rendering is
         *   unaffected. Log-level assertions keep working via Monolog
         *   TestHandler (CapturesLogRecords).
         */
        Http::preventStrayRequests();
        Sleep::fake(syncWithCarbon: true);
        Exceptions::fake();
    }
}

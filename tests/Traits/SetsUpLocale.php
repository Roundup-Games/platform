<?php

namespace Tests\Traits;

use Illuminate\Support\Facades\URL;

/**
 * Provides URL::defaults(['locale' => 'en']) for PHPUnit-style test classes.
 *
 * Pest tests inherit this via the global beforeEach in tests/Pest.php,
 * but traditional TestCase classes need it explicitly. Use this trait
 * instead of calling URL::defaults() manually in each setUp().
 */
trait SetsUpLocale
{
    protected function setUp(): void
    {
        parent::setUp();

        URL::defaults(['locale' => 'en']);
    }
}

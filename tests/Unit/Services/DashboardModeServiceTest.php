<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\DashboardModeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class DashboardModeServiceTest extends TestCase
{
    private DashboardModeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DashboardModeService;
        Cache::flush();
    }

    // ── Mode resolution ─────────────────────────────────────────────────

    public function test_resolve_returns_newcomer_for_new_user_with_no_attended_games(): void
    {
        $service = $this->partialMock(DashboardModeService::class, function ($mock) {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('attendedGameCount')->andReturn(0);
        });

        $user = $this->makeUser(createdDaysAgo: 5);
        $mode = $service->resolve($user);

        $this->assertSame('newcomer', $mode);
    }

    public function test_resolve_returns_established_for_old_user_with_no_attended_games(): void
    {
        $service = $this->partialMock(DashboardModeService::class, function ($mock) {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('attendedGameCount')->andReturn(0);
        });

        $user = $this->makeUser(createdDaysAgo: 60);
        $mode = $service->resolve($user);

        $this->assertSame('established', $mode);
    }

    public function test_resolve_returns_established_for_new_user_with_attended_games(): void
    {
        $service = $this->partialMock(DashboardModeService::class, function ($mock) {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('attendedGameCount')->andReturn(3);
        });

        $user = $this->makeUser(createdDaysAgo: 5);
        $mode = $service->resolve($user);

        $this->assertSame('established', $mode);
    }

    public function test_resolve_returns_established_for_old_user_with_attended_games(): void
    {
        $service = $this->partialMock(DashboardModeService::class, function ($mock) {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('attendedGameCount')->andReturn(5);
        });

        $user = $this->makeUser(createdDaysAgo: 60);
        $mode = $service->resolve($user);

        $this->assertSame('established', $mode);
    }

    // ── Boundary: exactly 30 days ───────────────────────────────────────

    public function test_resolve_returns_established_at_exactly_30_days(): void
    {
        $service = $this->partialMock(DashboardModeService::class, function ($mock) {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('attendedGameCount')->andReturn(0);
        });

        $user = $this->makeUser(createdDaysAgo: 30);
        $mode = $service->resolve($user);

        $this->assertSame('established', $mode);
    }

    public function test_resolve_returns_newcomer_at_29_days(): void
    {
        $service = $this->partialMock(DashboardModeService::class, function ($mock) {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('attendedGameCount')->andReturn(0);
        });

        $user = $this->makeUser(createdDaysAgo: 29);
        $mode = $service->resolve($user);

        $this->assertSame('newcomer', $mode);
    }

    // ── Caching ─────────────────────────────────────────────────────────

    public function test_resolve_caches_result(): void
    {
        $service = $this->partialMock(DashboardModeService::class, function ($mock) {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('attendedGameCount')->once()->andReturn(0);
        });

        $user = $this->makeUser(createdDaysAgo: 5);
        $mode1 = $service->resolve($user);

        // Second call should use cache — attendedGameCount only called once
        $mode2 = $service->resolve($user);

        $this->assertSame('newcomer', $mode1);
        $this->assertSame('newcomer', $mode2);
    }

    public function test_invalidate_forgets_cached_mode(): void
    {
        $service = $this->partialMock(DashboardModeService::class, function ($mock) {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('attendedGameCount')->andReturn(0);
        });

        $user = $this->makeUser(createdDaysAgo: 5);
        $service->resolve($user);

        $service->invalidateForUser($user);

        $this->assertNull(Cache::get("dashboard:mode:{$user->id}"));
    }

    // ── Logging ─────────────────────────────────────────────────────────

    public function test_resolve_logs_mode_resolution(): void
    {
        $service = $this->partialMock(DashboardModeService::class, function ($mock) {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('attendedGameCount')->andReturn(0);
        });

        Log::shouldReceive('debug')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'dashboard.mode_resolved'
                    && isset($context['user_id'], $context['mode'], $context['account_age_days'], $context['attended_game_count']);
            });

        $user = $this->makeUser(createdDaysAgo: 5);
        $service->resolve($user);
    }

    public function test_invalidate_logs_cache_invalidation(): void
    {
        Log::shouldReceive('debug')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'dashboard.mode_cache_invalidated'
                    && isset($context['user_id']);
            });

        $user = $this->makeUser(createdDaysAgo: 5);
        $this->service->invalidateForUser($user);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Create a real User model (not persisted) with controlled created_at.
     */
    private function makeUser(int $createdDaysAgo): User
    {
        $user = new User;
        $user->id = random_int(10000, 99999);
        $user->created_at = now()->subDays($createdDaysAgo);
        $user->exists = false;

        return $user;
    }
}

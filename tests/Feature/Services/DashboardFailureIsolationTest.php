<?php

use App\Enums\DashboardSection;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\User;
use App\Services\DashboardCacheService;
use App\Services\DashboardEstablishedService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

// A computer that blows up on a chosen section, leaving the rest intact.
// DashboardEstablishedService has no constructor, so the anonymous child needs none.
function failingComputer(string $throwOn): DashboardEstablishedService
{
    return new class($throwOn) extends DashboardEstablishedService
    {
        public function __construct(private readonly string $throwOn) {}

        public function computeWeekData(User $user): array
        {
            if ($this->throwOn === 'week') {
                throw new RuntimeException('week boom');
            }

            return parent::computeWeekData($user);
        }

        public function computeActionCenter(User $user): array
        {
            if ($this->throwOn === 'action_center') {
                throw new RuntimeException('action center boom');
            }

            return parent::computeActionCenter($user);
        }
    };
}

describe('dashboard section failure isolation', function () {
    beforeEach(function () {
        Cache::flush();
        Queue::fake();
    });

    it('degrades a throwing section to its fallback without blanking others', function () {
        // One completed game owned by the user so a HEALTHY Contributions compute
        // returns hosted count = 1 — proving it ran for real, not via the fallback.
        $user = User::factory()->create();
        Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Completed,
        ]);

        // Week throws; every other Established section falls through to the parent.
        $this->app->bind(DashboardEstablishedService::class, fn () => failingComputer('week'));

        $log = Log::spy();
        $service = app(DashboardCacheService::class);

        // Week degrades to its fallback — empty days, zeroed summary — not an exception.
        $week = $service->getWeekData($user);
        expect($week['days'])->toBe([])
            ->and($week['summary']['total'])->toBe(0);

        // The failure is surfaced for observability.
        $log->shouldHaveReceived('warning', function (string $message, array $context): bool {
            return $message === 'dashboard.section_compute_failed'
                && ($context['section'] ?? null) === 'week'
                && ($context['exception'] ?? null) === RuntimeException::class
                && ($context['error'] ?? null) === 'week boom';
        });

        // A sibling section on the SAME service still computes normally.
        $contributions = $service->getContributions($user);
        expect($contributions['hosted']['count'])->toBe(1);
    });

    it('isolates failures on lock-protected sections too', function () {
        $user = User::factory()->create();

        // ActionCenter is usesLock() — its compute runs through computeWithLock().
        $this->app->bind(DashboardEstablishedService::class, fn () => failingComputer('action_center'));

        $log = Log::spy();
        $service = app(DashboardCacheService::class);

        // The lock path absorbs the throw inside computeSection, so the caller
        // never sees it; the section renders empty instead of erroring.
        $actionCenter = $service->getActionCenter($user);
        expect($actionCenter)->toBe([]);

        $log->shouldHaveReceived('warning', function (string $message, array $context): bool {
            return $message === 'dashboard.section_compute_failed'
                && ($context['section'] ?? null) === 'action_center';
        });
    });

    it('returns a view-safe empty shape for every section fallback', function () {
        foreach (DashboardSection::cases() as $section) {
            $fallback = $section->fallback();

            expect($fallback)->toBeArray();

            // List-shaped sections degrade to an empty list; the rest are keyed.
            $listShaped = in_array($section, [
                DashboardSection::Recaps,
                DashboardSection::ActionCenter,
                DashboardSection::HostAgain,
                DashboardSection::MilestoneCards,
            ], true);

            if ($listShaped) {
                expect($fallback)->toBe([]);
            } else {
                expect($fallback)->not->toBe([]);
            }
        }
    });
});

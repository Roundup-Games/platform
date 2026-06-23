<?php

namespace App\Enums;

use App\Services\DashboardCacheService;

/**
 * A Dashboard section — a named unit of dashboard content with its own cache key,
 * TTL, and invalidation rule.
 *
 * Each case IS its own cache configuration: key topology, TTL, stampede-protection,
 * mode eligibility, geohash requirement, and warm-dispatch behaviour are all declared
 * here. This is the single source of truth that replaces the 11-branch
 * {@see DashboardCacheService::invalidateForUser()} chains and the
 * per-section get/warm/compute triplets.
 *
 * Adding a section = adding one case + one computer match arm in the cache module.
 * No invalidation chain or warm-job branch is ever edited to add a section.
 *
 * Trending is excluded — it is tile-keyed (not user-keyed) and async-only
 * (no synchronous compute), so it follows a fundamentally different pattern
 * and stays on its own get/invalidate methods.
 */
enum DashboardSection: string
{
    case Week = 'week';
    case Feed = 'feed';
    case Opportunities = 'opportunities';
    case Contributions = 'contributions';
    case Recaps = 'recaps';
    case ActionCenter = 'action_center';
    case NewcomerWelcome = 'newcomer_welcome';
    case ProgressTracker = 'progress_tracker';
    case NearbyPeople = 'nearby_people';
    case NewcomerMatches = 'newcomer_matches';
    case HostAgain = 'host_again';
    case MilestoneCards = 'milestone_cards';

    /**
     * How the section's cache key is constructed.
     */
    public function keyShape(): KeyShape
    {
        return match ($this) {
            self::Week => KeyShape::WeekDate,
            self::Opportunities, self::NearbyPeople, self::NewcomerMatches => KeyShape::GeohashTracked,
            default => KeyShape::Single,
        };
    }

    /**
     * Cache TTL in seconds.
     */
    public function ttl(): int
    {
        return match ($this) {
            self::Week, self::ActionCenter, self::ProgressTracker => 300,
            self::Feed, self::NearbyPeople, self::Recaps => 900,
            self::Opportunities, self::NewcomerWelcome,
            self::NewcomerMatches, self::HostAgain => 600,
            self::Contributions, self::MilestoneCards => 3600,
        };
    }

    /**
     * Whether the section uses stampede protection (computeWithLock) on cache miss.
     */
    public function usesLock(): bool
    {
        return match ($this) {
            self::Opportunities, self::ActionCenter,
            self::NearbyPeople, self::NewcomerMatches => true,
            default => false,
        };
    }

    /**
     * Which Dashboard mode(s) the section renders in. Drives warm-job selection.
     */
    public function mode(): DashboardSectionMode
    {
        return match ($this) {
            self::NewcomerWelcome, self::ProgressTracker, self::NewcomerMatches => DashboardSectionMode::Newcomer,
            self::Feed, self::Opportunities, self::HostAgain, self::MilestoneCards => DashboardSectionMode::Established,
            default => DashboardSectionMode::Both,
        };
    }

    /**
     * Whether the section's compute requires a geohash tile.
     */
    public function requiresGeohash(): bool
    {
        return $this->keyShape() === KeyShape::GeohashTracked;
    }

    /**
     * Whether a cache miss dispatches WarmDashboardCache and logs dashboard.cache_miss.
     *
     * Recaps is the sole exception: it computes synchronously on miss without
     * dispatching a background warm or logging (its TTL is long and its data
     * changes infrequently).
     */
    public function dispatchesWarm(): bool
    {
        return $this !== self::Recaps;
    }

    /**
     * The cache key for a read/write, given the user and optional geohash.
     */
    public function readKey(string $userId, ?string $geohash4 = null): string
    {
        return match ($this->keyShape()) {
            KeyShape::Single => "dashboard:{$this->value}:{$userId}",
            KeyShape::WeekDate => "dashboard:{$this->value}:{$userId}:".now()->startOfWeek()->format('Y-m-d'),
            KeyShape::GeohashTracked => "dashboard:{$this->value}:{$userId}:{$geohash4}",
        };
    }

    /**
     * The stampede-protection lock key (only meaningful when {@see usesLock()}).
     */
    public function lockKey(string $userId): string
    {
        return "dashboard:compute:{$this->value}:{$userId}";
    }

    /**
     * The tracking-set key for a geohash-tracked section, or null for non-tracked.
     */
    public function trackingKey(string $userId): ?string
    {
        return $this->keyShape() === KeyShape::GeohashTracked
            ? "dashboard:{$this->value}:keys:{$userId}"
            : null;
    }

    /**
     * The warm-job trigger-type string used in Log::info and dispatch.
     */
    public function warmTrigger(): string
    {
        return "cache_miss_{$this->value}";
    }

    /**
     * All sections applicable to a given mode, honouring geohash availability.
     *
     * Used by the warm job to iterate mode-applicable sections.
     *
     * @return list<self>
     */
    public static function forWarm(string $mode, bool $hasGeohash): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $s) => match ($s->mode()) {
                DashboardSectionMode::Both => true,
                DashboardSectionMode::Newcomer => $mode === 'newcomer',
                DashboardSectionMode::Established => $mode === 'established',
            } && ($hasGeohash || ! $s->requiresGeohash()),
        ));
    }
}

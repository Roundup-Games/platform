<?php

namespace App\Services\Badges;

use App\Enums\BadgeEligibility;
use App\Enums\BadgeRarity;
use App\Models\BadgeDefinition;
use App\Models\BadgeUser;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Nightly badge rarity orchestrator (M069 S04, spec §2.4, D195): the
 * deferred second half of the badge jobs — recompute the job-owned
 * holders/eligible/rarity columns on badge_definitions that the gallery
 * and GM-directory surfaces read.
 *
 * Contract:
 *
 * - **No sync prepend** (deliberately unlike BadgesSweep): the 06:30
 *   sweep already prepends badges:sync fifteen minutes earlier, and the
 *   gallery self-heals on the next night's run if this one races a
 *   deploy. Re-syncing here would only duplicate work.
 * - **Failure isolation** — every definition recomputes in its own
 *   try/catch so one broken definition cannot starve the rest of their
 *   rarity refresh. Failures are logged (badges.rarity.failed with
 *   exception class + message) and returned so the command exits
 *   non-zero and M068's scheduled-task tracking records the failed run.
 * - **Idempotent** — the pass is a pure recompute of derived columns;
 *   re-running converges to the same rows (only rarity_computed_at
 *   advances).
 *
 * Semantics:
 *
 * - holders — non-revoked badge_user rows (a lapsed badge is still
 *   held-earned and still counts; only revocation removes it, spec
 *   principle 4).
 * - eligible — the population that could hold it, excluding anonymized
 *   accounts (MEM586): all non-anonymized users, or just those holding
 *   the Game Master role for GM-craft badges whose signal only GMs can
 *   produce.
 * - rarity — holders/eligible*100 mapped onto BadgeRarity tiers; an
 *   empty eligible population is div-by-zero guarded to 0%, which maps
 *   to legendary (no denominator, no percentage claim).
 *
 * Twin of the badges:rarity command (BadgesSweep command + service
 * precedent, D194): this class is the orchestrator the command, tests
 * and future admin surfaces share.
 */
class BadgesRarity
{
    /**
     * Recompute counts and rarity for every badge definition row, in
     * canonical order. Rows, not registry entries: a retired or
     * unregistered definition keeps its holders and keeps displaying
     * rarity in the gallery.
     *
     * @return array{
     *     definitions: int,
     *     failed: int,
     *     failures: array<int, array{badge: string, exception: string, message: string}>,
     *     durations_ms: array<string, int>
     * } failure info plus counts — `failed > 0` is the command's
     * non-zero-exit signal.
     */
    public function compute(): array
    {
        $startedAt = now();

        Log::info('badges.rarity.started');

        $failures = [];
        $durations = [];

        $definitions = BadgeDefinition::query()->orderBy('sort_order')->get();

        foreach ($definitions as $definition) {
            $definitionStartedAt = now();

            try {
                $this->computeFor($definition);
            } catch (Throwable $e) {
                // Failure isolation (see class docblock): record, log, keep
                // computing — the next badge's rarity must not be held
                // hostage by this one.
                $failures[] = [
                    'badge' => $definition->key,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ];

                Log::error('badges.rarity.failed', [
                    'badge' => $definition->key,
                    'exception_class' => $e::class,
                    'exception' => $e->getMessage(),
                ]);
            } finally {
                // Carbon 3's diffInMilliseconds() is float; the contract
                // (and every consumer, log context included) speaks int ms.
                $durations[$definition->key] = (int) round(now()->diffInMilliseconds($definitionStartedAt));
            }
        }

        Log::info('badges.rarity.completed', [
            'definitions' => count($definitions),
            'failed' => count($failures),
            'durations_ms' => $durations,
            'failures' => $failures,
            'duration_ms' => $startedAt->diffInMilliseconds(now()),
        ]);

        return [
            'definitions' => count($definitions),
            'failed' => count($failures),
            'failures' => $failures,
            'durations_ms' => $durations,
        ];
    }

    /**
     * Recompute one definition's four job-owned columns.
     *
     * @throws Throwable any query/cast failure bubbles to the loop's
     *                   isolation catch — a failed definition keeps its
     *                   previous (stale) values until the next run.
     */
    protected function computeFor(BadgeDefinition $definition): void
    {
        // Numerator and denominator share one population rule: an
        // anonymized account leaves both sides, and a GM-craft badge's
        // numerator keeps the GM-role filter of its denominator
        // (otherwise the share drifts — and can exceed 100% — as
        // holders anonymize out of the pool or lose the role on
        // membership lapse while their never-lapsing milestone holds
        // stay counted).
        $isGmCraft = $definition->eligibility === BadgeEligibility::GameMasters;

        $holders = BadgeUser::query()
            ->where('badge_definition_id', $definition->id)
            ->whereNull('revoked_at')
            ->whereHas('user', fn ($query) => $query
                ->notAnonymized()
                ->when($isGmCraft, fn ($q) => $q->role('Game Master')))
            ->count();

        $eligible = $isGmCraft
            ? User::role('Game Master')->notAnonymized()->count()
            : User::query()->notAnonymized()->count();

        $percentage = $eligible > 0 ? ($holders / $eligible) * 100 : 0.0;

        $definition->update([
            'holders_count' => $holders,
            'eligible_count' => $eligible,
            'rarity_class' => BadgeRarity::fromPercentage($percentage),
            'rarity_computed_at' => now(),
        ]);
    }
}

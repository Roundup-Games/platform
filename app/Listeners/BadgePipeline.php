<?php

namespace App\Listeners;

use App\Enums\BadgeSource;
use App\Events\MembershipActivated;
use App\Models\BadgeDefinition;
use App\Services\Badges\BadgeEvaluator;
use App\Services\Badges\BadgeRegistry;
use App\Services\Badges\BadgeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * The badge engine's event-driven award seam (M069 spec §2.2): one queued
 * listener behind membership domain events, running EVERY registered
 * evaluator against the acting user and routing each proposal through
 * the audited BadgeService write side.
 *
 * Registered for MembershipActivated only (AppServiceProvider). Badge
 * lapses are sweep-owned and quiet (spec §4) — MembershipEnded
 * deliberately has no badge listener.
 *
 * ShouldQueue (Horizon in production, sync in tests), modeled on
 * HandleGameSystemTicketResolved. Definitions are iterated wholesale:
 * dormant ones carry NoOpEvaluator and BadgeService refuses dormant
 * writes anyway, so a new registry entry lights up here without this
 * class ever needing to change. One guard: a badge_definitions table
 * with zero rows means the engine was never provisioned (no
 * `badges:sync`) — the pipeline stays inert instead of throwing
 * unknown-key on every membership event. A partially-synced drift
 * still fails loudly and visibly in the queue.
 */
class BadgePipeline implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(MembershipActivated $event): void
    {
        // Unprovisioned engine (badge_definitions empty — sync never ran):
        // stay inert. Every membership event would otherwise throw.
        if (! BadgeDefinition::query()->exists()) {
            Log::info('Badge pipeline skipped a membership event: badge definitions not provisioned');

            return;
        }

        // Deleted between dispatch and delivery: skip quietly — there is
        // no shelf left to write to. (On a real queue the wakeup fetch
        // revalidates by id; a user deleted mid-flight surfaces as a
        // visible failed job instead.)
        $user = $event->user->fresh();

        if ($user === null) {
            Log::info('Badge pipeline skipped a membership event for a deleted user');

            return;
        }

        $registry = app(BadgeRegistry::class);
        $service = app(BadgeService::class);

        foreach ($registry->all() as $definition) {
            // Per-evaluator failure isolation (the BadgesSweep contract):
            // one evaluator's runtime exception must not cost the OTHER
            // badges their immediate awards on this event — record, log,
            // keep going. The nightly sweep is the self-heal backstop for
            // whatever this run skipped (stateless recompute), so the
            // lost immediacy is the only cost of an isolated failure.
            try {
                /** @var BadgeEvaluator $evaluator */
                $evaluator = app($definition->evaluator);

                $award = $evaluator->evaluate($user);

                if ($award === null) {
                    continue;
                }

                $service->award(
                    $user,
                    $award->badgeKey,
                    $award->tierKey,
                    $this->resolveEvidence($award->evidenceType, $award->evidenceId),
                    BadgeSource::Automatic,
                );
            } catch (\Throwable $e) {
                Log::error('badges.pipeline.evaluator_failed', [
                    'badge' => $definition->key,
                    'user_id' => $user->id,
                    'exception_class' => $e::class,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Rehydrate the proposal's evidence model from its morph pointer
     * (D170 alias + key). Evidence is best-effort at award time: the
     * eligibility decision already happened in the evaluator, so a
     * record that vanished in between awards with a null pointer
     * rather than failing the job.
     */
    private function resolveEvidence(?string $evidenceType, ?string $evidenceId): ?Model
    {
        if ($evidenceType === null || $evidenceId === null) {
            return null;
        }

        $class = Relation::getMorphedModel($evidenceType) ?? $evidenceType;

        if (! is_a($class, Model::class, true)) {
            return null;
        }

        return $class::query()->whereKey($evidenceId)->first();
    }
}

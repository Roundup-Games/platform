<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Shared persistence pipeline for the twin session-creation forms
 * (Games\CreateGame and Campaigns\CreateCampaign).
 *
 * The service owns everything that happens AFTER the Livewire component has
 * authorized the user, validated the form, and built its translatable values:
 * the bench-mode GM gate, the gathering scrub (bench mode and reliability only),
 * pivot canonicalization,
 * the create transaction (model + owner participant + canonical pivot sync +
 * the zero-offered-system invariant), the cover upload, and the GM short
 * link. Components keep authorization, validation, Livewire error feedback,
 * and their specific pre/post steps (host-a-table gates, flash/redirect).
 *
 * The service never touches Livewire state: everything it needs arrives in
 * the validated draft, and model-specific persistence is injected via the
 * $makeModel closure (which runs INSIDE the create transaction).
 *
 * @phpstan-type SessionCreationDraft array{
 *     validated: array<string, mixed>,
 *     translatable: array<string, array<string, string>>,
 *     safety_rules: mixed,
 *     vibe_flags: list<string>,
 *     bench_mode: bool,
 *     complexity: string|null,
 *     min_reliability_preference?: string|null,
 *     cover_image: UploadedFile|null,
 *     recreates_game_id?: string|null,
 * }
 * @phpstan-type SessionCreationContext array{
 *     validated: array<string, mixed>,
 *     translatable: array<string, array<string, string>>,
 *     safety_rules: mixed,
 *     vibe_flags: list<string>,
 *     bench_mode: bool,
 *     complexity: string|null,
 *     min_reliability_preference: string|null,
 *     pivot_system_ids: list<string>,
 *     owner_id: int|string|null,
 *     recreates_game_id: string|null,
 * }
 */
class SessionCreationService
{
    public function __construct(
        private readonly OwnerParticipantService $ownerParticipants,
        private readonly ShortLinkService $shortLinks,
    ) {}

    /**
     * Create a session entity (game or campaign) from a validated form draft.
     *
     * @template TModel of Game|Campaign
     *
     * @param  User  $creator  The authenticated creator (same user the component authorized).
     * @param  string  $entityType  'game'|'campaign'; drives log labels/messages so log output stays identical.
     * @param  SessionCreationDraft  $draft
     * @param  (Closure(SessionCreationContext): TModel)  $makeModel  Persists the model; runs inside the transaction.
     * @param  (Closure(TModel): void)|null  $afterSync  Model-specific step that must run inside the transaction after the pivot sync (game: flush the hosting event's offered-systems cache).
     * @param  (Closure(TModel): void)|null  $onCreated  Model-specific logging, invoked after the cover upload and before the GM short link (preserves the original log order).
     * @return TModel
     */
    public function create(
        User $creator,
        string $entityType,
        array $draft,
        Closure $makeModel,
        ?Closure $afterSync = null,
        ?Closure $onCreated = null,
    ): Game|Campaign {
        if (! in_array($entityType, ['game', 'campaign'], true)) {
            throw new \InvalidArgumentException("Unknown session entity type [{$entityType}].");
        }

        $validated = $draft['validated'];

        // Vibe flags are extracted (and validated against the VibeFlag enum)
        // by the component; consume them as-is here.
        $vibeFlags = $draft['vibe_flags'];

        // Gate bench_mode to GM users only (defense-in-depth; UI disables toggle for non-GMs)
        $benchMode = $draft['bench_mode'];
        if ($benchMode && ! $creator->isGM()) {
            Log::warning('Non-GM user attempted to enable bench_mode on '.$entityType.' creation', [
                'user_id' => $creator->id,
                'attempted_bench_mode' => true,
            ]);
            $benchMode = false;
        }

        // Gatherings are multi-system social sessions: the bench switch and the
        // reliability preference are not on the gathering form, so they are forced
        // clean. The complexity IS on that form (D325): the value the host typed is kept.
        $isGathering = ($validated['game_type'] ?? null) === 'gathering';
        $complexity = $draft['complexity'];
        $minReliabilityPreference = $isGathering ? null : ($draft['min_reliability_preference'] ?? null);
        $benchMode = $isGathering ? false : $benchMode;

        // Canonical system set: the <entity>_game_system pivot is the source of
        // truth for which systems this session offers. For a Gathering the host
        // picks a set via the multi-select; for a focused board_game / ttrpg the
        // single picker carries one system.
        $pivotSystemIds = $this->canonicalSystemIds($validated, $isGathering);

        // Linked recreate (D318/D319): the cancelled predecessor this game
        // replaces, already validated by the component (its game, cancelled,
        // declared, no replacement yet). Only a well-formed id travels on.
        $recreatesGameId = $draft['recreates_game_id'] ?? null;
        $recreatesGameId = is_string($recreatesGameId) && $recreatesGameId !== '' ? $recreatesGameId : null;

        $model = DB::transaction(function () use ($draft, $creator, $entityType, $makeModel, $afterSync, $vibeFlags, $benchMode, $complexity, $minReliabilityPreference, $pivotSystemIds, $recreatesGameId) {
            /** @var SessionCreationContext $context */
            $context = [
                'validated' => $draft['validated'],
                'translatable' => $draft['translatable'],
                'safety_rules' => $draft['safety_rules'],
                'vibe_flags' => $vibeFlags,
                'bench_mode' => $benchMode,
                'complexity' => $complexity,
                'min_reliability_preference' => $minReliabilityPreference,
                'pivot_system_ids' => $pivotSystemIds,
                'owner_id' => $creator->getKey(),
                'recreates_game_id' => $recreatesGameId,
            ];

            $model = $makeModel($context);

            $ownerParticipant = $this->ownerParticipants;
            if ($model instanceof Game) {
                $ownerParticipant->ensureOwnerParticipant($model);
            } elseif ($model instanceof Campaign) {
                $ownerParticipant->ensureCampaignOwnerParticipant($model);
            }

            // Sync the canonical pivot. Runs inside the create transaction so a
            // failure rolls the whole entity back. empty() would detach
            // everything, so guard against an empty set (single-system
            // sessions always have one).
            if ($pivotSystemIds !== []) {
                $model->gameSystems()->sync($pivotSystemIds);
            }

            if ($afterSync !== null) {
                $afterSync($model);
            }

            // Defense-in-depth invariant: every session entity must offer at
            // least one game system — for a campaign, every session spawned
            // from it inherits the set, so a gap here cascades downstream.
            // The validation checks in the Livewire forms enforce this, but if
            // a future change bypasses them (or a new creation path skips the
            // form), this assertion throws and rolls back the entire
            // transaction rather than persisting a systemless session — the
            // exact data corruption discovered in production (game 62a41a7e).
            if ($model->gameSystems()->count() === 0) {
                throw new RuntimeException(ucfirst($entityType).' created without a game system.');
            }

            return $model;
        });

        // Linked recreate: the host's late-cancel offence on the cancelled
        // predecessor turns neutral ONLY now that the replacement is committed
        // (D319). Deliberately outside and after the create transaction — the
        // game exists whether or not the offence resolves. Failure-isolated:
        // a failed resolve leaves the offence standing, which is the safe
        // default, and must never make game creation fail.
        if ($model instanceof Game && $model->recreates_game_id !== null) {
            $this->resolveLinkedRecreate($model);
        }

        // Persist the host-uploaded cover to the Spatie 'cover' collection.
        // singleFile() on the collection means a fresh upload replaces any
        // prior cover. Runs OUTSIDE the create transaction: media storage
        // writes files and a media row, neither of which the entity row
        // depends on, and Spatie's medialibrary does not participate in the
        // caller's DB transaction safely.
        $coverImage = $draft['cover_image'];
        if ($coverImage instanceof UploadedFile) {
            try {
                $model->addMedia($coverImage)->toMediaCollection('cover');

                Log::info(ucfirst($entityType).' cover image uploaded', [
                    $entityType.'_id' => $model->getKey(),
                    'owner_id' => $creator->id,
                    'mime' => $coverImage->getMimeType(),
                    'size' => $coverImage->getSize(),
                ]);
            } catch (\Throwable $e) {
                // Upload failures are non-fatal: the entity is already created
                // and resolveCoverUrl() falls back to the representative
                // system cover. Surface the failure for follow-up.
                Log::warning(ucfirst($entityType).' cover image upload failed', [
                    $entityType.'_id' => $model->getKey(),
                    'owner_id' => $creator->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($onCreated !== null) {
            $onCreated($model);
        }

        // Auto-generate short link for GMs
        if ($creator->isGM()) {
            try {
                $this->shortLinks->createLink($model, $creator, [
                    'label' => 'Default',
                    'expires_at' => now()->addDays(30),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to auto-generate short link for '.$entityType, [
                    $entityType.'_id' => $model->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $model;
    }

    /**
     * Resolve the linked recreate for a just-published game, never throwing.
     *
     * Mirrors the offence guard in GamesPage::cancelGame: any failure is logged
     * as recreate.resolve_failed and swallowed, because the game is already
     * created and the offence standing is the safe default (D319).
     */
    private function resolveLinkedRecreate(Game $game): void
    {
        $recreatesGameId = $game->recreates_game_id;

        if ($recreatesGameId === null) {
            return;
        }

        try {
            $predecessor = Game::query()->find($recreatesGameId);

            if ($predecessor !== null) {
                app(AttendanceService::class)->resolveLinkedRecreate($predecessor, $game);
            }
        } catch (\Throwable $e) {
            Log::error('recreate.resolve_failed', [
                'game_id' => $game->getKey(),
                'recreates_game_id' => $recreatesGameId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Canonical offered-system set from the validated form data. The gathering
     * branch keeps every picked system; the focused branch keeps the single
     * picked system. Values arrive pre-validated as UUID strings, so the
     * is_string filters are pure type-narrowing for static analysis.
     *
     * @param  array<string, mixed>  $validated
     * @return list<string>
     */
    private function canonicalSystemIds(array $validated, bool $isGathering): array
    {
        if ($isGathering) {
            $systems = $validated['game_systems'] ?? [];
            $systems = is_array($systems) ? $systems : [];

            $ids = [];
            foreach ($systems as $system) {
                if (is_string($system)) {
                    $ids[] = $system;
                }
            }

            return $ids;
        }

        $single = $validated['game_system_id'] ?? null;

        return is_string($single) ? [$single] : [];
    }
}

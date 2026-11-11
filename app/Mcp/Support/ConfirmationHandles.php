<?php

namespace App\Mcp\Support;

use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Single-use confirmation handles for the agent surface (M079/S02/T02).
 *
 * Waitlist promotions open a server-authoritative confirmation window: the
 * promoted participant row flips to Pending with a confirmation_expires_at
 * deadline, and only the human who was promoted may confirm. The write
 * tools NEVER confirm on the user's behalf — agents retry and guess — so
 * when a join or apply trips over one of those open windows, the tool
 * returns the pending state together with an opaque handle the user can
 * act on.
 *
 * Single-use has two halves:
 *  - one live handle per participant: issuing again (a retry without an
 *    idempotency key, or a second agent session) invalidates the previous
 *    handle immediately — only the newest is consumable;
 *  - consumption is destructive: {@see consume()} pulls and deletes the
 *    entry, so the same handle resolves exactly once. A handle that
 *    resolves to a row whose window has closed or was already confirmed
 *    answers null.
 *
 * The cache stores ids only — never free text, never the acting agent's
 * arguments — and every entry expires with the confirmation window it
 * references.
 */
final class ConfirmationHandles
{
    /** Opaque-handle prefix (auditable provenance in logs/dumps). */
    public const string PREFIX = 'mcpconfirm_';

    private const string ENTRY_PREFIX = 'mcp:confirmation:handle:';

    private const string POINTER_PREFIX = 'mcp:confirmation:participant:';

    /**
     * Issue the single live confirmation handle for a pending waitlist
     * promotion, invalidating any handle previously issued for the same
     * participant row.
     *
     * @return array{handle: string, expires_at: string}
     *
     * @throws InvalidArgumentException When the row carries no open
     *                                  confirmation window — callers guard this before calling; a violation
     *                                  is a programming error, not an agent input problem.
     */
    public function issue(User $user, GameParticipant $participant): array
    {
        $expiresAt = $participant->confirmation_expires_at;

        if ($expiresAt === null || $expiresAt->isPast()) {
            throw new InvalidArgumentException('A confirmation handle requires an open confirmation window.');
        }

        $ttl = max(1, (int) now()->diffInSeconds($expiresAt, false));
        $handle = self::PREFIX.bin2hex(random_bytes(20));

        // Regeneration invalidates the predecessor: exactly one handle per
        // pending confirmation stays consumable.
        $pointerKey = self::POINTER_PREFIX.$participant->id;
        $previous = Cache::get($pointerKey);

        if (is_string($previous) && $previous !== '') {
            Cache::forget(self::ENTRY_PREFIX.hash('sha256', $previous));
        }

        Cache::put($pointerKey, $handle, $ttl);
        Cache::put(self::ENTRY_PREFIX.hash('sha256', $handle), [
            'user_id' => $user->id,
            'participant_id' => $participant->id,
            'game_id' => $participant->game_id,
            'expires_at' => $expiresAt->toIso8601String(),
        ], $ttl);

        return ['handle' => $handle, 'expires_at' => $expiresAt->toIso8601String()];
    }

    /**
     * Consume a handle exactly once and resolve the confirmation it names.
     *
     * Destructive on every path: the entry is deleted whether or not the
     * referenced confirmation is still actionable, so a replayed or stale
     * handle can never be double-spent. The confirmation itself is still
     * executed by the web surface — the MCP tools never call this.
     *
     * Deliberately an UNWIRED seam as of M079: no production caller binds
     * a user-presented handle to its row yet (the promoted user confirms
     * through the existing web confirm affordance the tool response links
     * to; retry safety on the agent side comes from reissue-invalidation,
     * which the ParticipantWriteToolsTest suite pins). This method is the
     * ready-made binding point for a future handle-consuming confirm
     * route — until one exists, it is exercised only by tests.
     *
     * @return array{user_id: string, participant_id: string, game_id: string}|null
     */
    public function consume(string $handle): ?array
    {
        if (! str_starts_with($handle, self::PREFIX)) {
            return null;
        }

        $entry = Cache::pull(self::ENTRY_PREFIX.hash('sha256', $handle));

        if (! is_array($entry)) {
            return null;
        }

        $participantId = $entry['participant_id'] ?? null;
        $userId = $entry['user_id'] ?? null;

        if (! is_string($participantId) || ! is_string($userId)) {
            return null;
        }

        $participant = GameParticipant::query()->find($participantId);

        if ($participant === null
            || $participant->confirmation_expires_at === null
            || $participant->confirmation_expires_at->isPast()
            || $participant->user_id !== $userId
        ) {
            return null;
        }

        // Pointer cleanup: the consumed handle is no longer the live one.
        $pointerKey = self::POINTER_PREFIX.$participant->id;

        if (Cache::get($pointerKey) === $handle) {
            Cache::forget($pointerKey);
        }

        /** @var array{user_id: string, participant_id: string, game_id: string} */
        return [
            'user_id' => $userId,
            'participant_id' => $participant->id,
            'game_id' => $participant->game_id,
        ];
    }
}

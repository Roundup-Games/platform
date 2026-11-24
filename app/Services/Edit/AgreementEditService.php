<?php

namespace App\Services\Edit;

use App\Dto\EditResult;
use App\Enums\ActivityType;
use App\Enums\CalendarSyncOperation;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Notifications\EntityUpdated;
use App\Services\ActivityLogService;
use App\Services\Calendar\CalendarSyncDispatcher;
use App\Services\NotificationService;
use App\Services\WaitlistService;
use App\Support\Edit\FieldTiers;
use BackedEnum;
use Carbon\Carbon;
use DateTimeInterface;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * The ONE write path for owner and admin field changes on a game or a
 * campaign — decisions D313 (consent tiers) and D314 (the agreement lock).
 *
 * Every surface that edits one of those entities — the owner inline edit, the
 * detail-page edit mode, an admin — hands its changes here and never calls
 * fill()/save() on the Tier-classified columns itself. FieldTiers decides what
 * a field is worth to a participant; HasAgreementLock decides whether the
 * agreement exists; this class is the only place that acts on both:
 *
 *  1. An unclassified key is a programmer error and throws. Nothing is ever
 *     dropped or treated as free because nobody classified it.
 *  2. A change that already equals the stored value is not a change. A no-op
 *     writes nothing, logs nothing and notifies nobody — without this step
 *     every edit-mode save would report the fields it merely re-submits.
 *  3. On a LOCKED entity (it has ever had a confirmed non-owner participant),
 *     a change to a Tier-1 field refuses the whole request with a
 *     ValidationException under the `edit` key: never a partial apply.
 *  4. Everything else is written, logged, re-synced to the roster's calendars
 *     (games) and announced to the approved participants.
 *
 * This class does not authorize: the calling surface owns `update` policy
 * checks and value validation. Values arrive STORAGE-READY — callers convert
 * wall-clock input with wall_time_to_utc() first, and this class never
 * converts timezones.
 *
 * Two things are deliberately not written here: the offered game systems (a
 * pivot the calling surface syncs — see self::PIVOT_KEYS) and the cover image
 * (the media library).
 */
class AgreementEditService
{
    /**
     * Keys FieldTiers classifies that are not a column this service fills: the
     * offered game systems live on a pivot (game_game_system /
     * campaign_game_system). They pass through the gate — Tier 1, so a locked
     * entity refuses them — but the sync stays with the calling edit surface.
     * The model's setGameSystemIdAttribute bridge only syncs on CREATE, so a
     * fill() here would capture the ids and write nothing.
     *
     * @var array<string, true>
     */
    private const array PIVOT_KEYS = [
        'game_system_id' => true,
        'game_systems' => true,
    ];

    /**
     * spatie-translatable JSON columns: `$entity->name` is only the
     * CURRENT-locale string, so these need their own comparison.
     *
     * @var array<string, true>
     */
    private const array TRANSLATABLE_KEYS = [
        'name' => true,
        'description' => true,
    ];

    public function __construct(
        private readonly WaitlistService $waitlist,
        private readonly NotificationService $notifications,
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * Apply one edit request to a game or a campaign.
     *
     * @param  array<string, mixed>  $changes  Field (machine column name) => storage-ready value.
     *
     * @throws InvalidArgumentException When a key is not a field FieldTiers classifies for this model, or when a value has a shape its field cannot take. A programmer error, never a user error.
     * @throws ValidationException When the entity is agreement-locked and the request still changes a Tier-1 field. Nothing is written.
     */
    public function apply(Game|Campaign $entity, array $changes, User $actor): EditResult
    {
        // 1. Every key must be a field FieldTiers knows. The two
        // direction-dependent keys are classified with their values in step 3.
        foreach (array_keys($changes) as $field) {
            if ($field === 'cover_image') {
                throw new InvalidArgumentException(sprintf(
                    'Field [cover_image] is stored through the media library. Upload it through the cover upload, not through %s.',
                    self::class,
                ));
            }

            if (! array_key_exists($field, FieldTiers::DIRECTION_DEPENDENT)) {
                FieldTiers::tierOf($entity::class, $field);
            }
        }

        // 2. Drop every change whose value already equals the stored one.
        $pending = [];
        foreach ($changes as $field => $value) {
            if (! $this->isNoOp($entity, $field, $value)) {
                $pending[$field] = $value;
            }
        }

        if ($pending === []) {
            return new EditResult(applied: [], wasLocked: $entity->isAgreementLocked());
        }

        // 3. Classify what remains.
        $tiers = [];
        foreach ($pending as $field => $value) {
            $tiers[$field] = $this->tierOfChange($entity, $field, $value);
        }

        $lockedFields = array_keys(array_filter(
            $tiers,
            fn (int $tier): bool => $tier === FieldTiers::TIER_LOCKED,
        ));

        $previousMaxPlayers = self::currentMaxPlayers($entity);

        // 4 + 5. One transaction: the gate, the write and the seat fill that
        // belongs to the write either all hold or none of them happened.
        [$applied, $wasLocked] = DB::transaction(function () use ($entity, $pending, $lockedFields, $previousMaxPlayers, $actor): array {
            // Serialize against every approval path that takes the entity row
            // first (the canonical lock order, see .ai/rules/services.md): the
            // lock is read under that row lock, so an approval either commits
            // before this read — and the Tier-1 change is refused — or after it.
            $entity->newQuery()->whereKey($entity->getKey())->lockForUpdate()->firstOrFail();

            $wasLocked = $entity->isAgreementLocked();

            if ($wasLocked && $lockedFields !== []) {
                Log::warning('edit.rejected_locked', [
                    'entity_type' => $entity instanceof Game ? 'game' : 'campaign',
                    'entity_id' => $entity->id,
                    'actor_id' => $actor->id,
                    // Field NAMES only — an attempted new date or price is not log content.
                    'fields' => $lockedFields,
                ]);

                throw ValidationException::withMessages([
                    'edit' => $entity instanceof Game
                        ? __('games.error_agreement_locked')
                        : __('campaigns.error_agreement_locked'),
                ]);
            }

            $applied = array_diff_key($pending, self::PIVOT_KEYS);

            if ($applied !== []) {
                $entity->fill($applied)->save();
            }

            // 5b. New seats are filled from the waitlist. Nothing else promotes
            // on an increase, so without this a raise from an edit surface
            // would lift the limit and strand the queue. Same call, same
            // transaction, as CapacityService::increase().
            $newMaxPlayers = $applied['max_players'] ?? null;

            if (is_numeric($newMaxPlayers) && (int) $newMaxPlayers > $previousMaxPlayers) {
                $this->waitlist->promoteAllOnCancel($entity);
            }

            return [$applied, $wasLocked];
        });

        $changedFields = array_keys($applied);

        // Only game system keys were pending: the gate passed, the caller owns
        // the pivot, and there is nothing here to log or announce.
        if ($applied === []) {
            return new EditResult(applied: [], wasLocked: $wasLocked);
        }

        $isGame = $entity instanceof Game;

        // 6.
        $this->activityLog->log(
            $isGame ? ActivityType::GameUpdated : ActivityType::CampaignUpdated,
            $actor,
            $entity,
            ['changed_fields' => $changedFields],
        );

        Log::info($isGame ? 'Game updated' : 'Campaign updated', [
            $isGame ? 'game_id' : 'campaign_id' => $entity->id,
            'owner_id' => $entity->owner_id,
            'changed_fields' => $changedFields,
        ]);

        // 7. A venue/detail edit re-upserts the game on every connected
        // calendar that carries it (managed-sync trigger, M083).
        if ($isGame) {
            CalendarSyncDispatcher::dispatchToRoster($entity, CalendarSyncOperation::Upsert);
        }

        // 8.
        $this->notifyParticipants($entity, $changedFields);

        return new EditResult(applied: $applied, wasLocked: $wasLocked);
    }

    /**
     * Whether a submitted value already equals what the entity stores — the
     * comparison GamesPage::saveGameEdit and CampaignsPage::saveCampaignEdit
     * always made, generalized per field shape.
     */
    private function isNoOp(Game|Campaign $entity, string $field, mixed $value): bool
    {
        if (isset(self::TRANSLATABLE_KEYS[$field])) {
            return $this->isUnchangedTranslation($entity, $field, $value);
        }

        if (isset(self::PIVOT_KEYS[$field])) {
            return self::stringSet($value) === self::stringSet($entity->gameSystems()->pluck('id')->all());
        }

        if ($field === 'vibe_flags') {
            // A flag list is a set: re-ordering the same flags is not an edit.
            return self::stringSet($value) === self::stringSet($entity->getAttribute($field));
        }

        if ($field === 'location') {
            // The inline edit writes the freeform `details` key only, and that
            // is the part a person ever sees.
            return self::locationDetails($value) === self::locationDetails($entity->getAttribute($field));
        }

        return self::isSameValue($entity, $field, $entity->getAttribute($field), $value);
    }

    /**
     * spatie-translatable comparison. Two value shapes are legal:
     *
     *  - a plain string (or null): the inline edit's write for the CURRENT
     *    locale, compared against that locale's translation — with the
     *    fallback locale, exactly like `$entity->name` resolves it;
     *  - a `array<string, string>` locale => translation map, what the edit
     *    mode's buildTranslatableValues() produces, compared against the
     *    stored map with empty locales dropped from BOTH sides (a missing
     *    translation and an empty one are the same "not translated").
     *
     * A list or any other scalar is a shape these columns cannot take.
     */
    private function isUnchangedTranslation(Game|Campaign $entity, string $field, mixed $value): bool
    {
        if (is_array($value)) {
            if (array_is_list($value) && $value !== []) {
                throw new InvalidArgumentException(sprintf(
                    'Field [%s] expects a locale => translation map, a plain string or null; a list was given.',
                    $field,
                ));
            }

            return self::translationMap($value) === self::translationMap($entity->getTranslations($field));
        }

        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'Field [%s] expects a locale => translation map, a plain string or null; %s was given.',
                $field,
                get_debug_type($value),
            ));
        }

        return self::text($entity->getTranslation($field, app()->getLocale())) === self::text($value);
    }

    /**
     * Tier of one real change, by field kind.
     *
     * @return int One of FieldTiers::TIER_LOCKED, TIER_NOTIFIED, TIER_FREE
     */
    private function tierOfChange(Game|Campaign $entity, string $field, mixed $value): int
    {
        if ($field === 'max_players') {
            if (! is_numeric($value)) {
                throw new InvalidArgumentException('Field [max_players] expects a number.');
            }

            return FieldTiers::maxPlayersTier(self::currentMaxPlayers($entity), (int) $value);
        }

        if ($field === 'visibility') {
            // A null visibility cannot exist (NOT NULL column); '' lets FieldTiers refuse it loudly if it ever does.
            $currentValue = $entity->visibility instanceof Visibility ? $entity->visibility->value : '';
            $new = $value instanceof BackedEnum ? $value->value : $value;

            // FieldTiers refuses an unknown value itself rather than call the
            // change free; only the shape is checked here.
            if (! is_string($new)) {
                throw new InvalidArgumentException('Field [visibility] expects a Visibility value.');
            }

            return FieldTiers::visibilityTier($currentValue, $new);
        }

        return FieldTiers::tierOf($entity::class, $field);
    }

    /**
     * Equality for one column value, by the shape the model reads it back in.
     *
     * null and '' are the same "not set" (a nullable text column normalized
     * the way GamesPage::saveGameEdit always did). Cast-numeric columns
     * compare numerically (a duration of 2 and "2.0" are one value), dates as
     * instants, JSON columns as documents whose object key order does not
     * matter but whose list order does. Everything else compares as a string.
     */
    private static function isSameValue(Game|Campaign $entity, string $field, mixed $current, mixed $value): bool
    {
        $current = $current instanceof BackedEnum ? $current->value : $current;
        $value = $value instanceof BackedEnum ? $value->value : $value;

        if (self::isBlank($current) && self::isBlank($value)) {
            return true;
        }

        if (self::hasNumericCast($entity, $field)) {
            // A null numeric column counts as 0: an unset price and a submitted
            // 0 are both "free", and reporting that as a change would announce
            // a price change that never happened.
            $currentNumber = is_numeric($current) ? (float) $current : 0.0;

            return is_numeric($value) && $currentNumber === (float) $value;
        }

        if ($current instanceof DateTimeInterface) {
            return self::isSameInstant($current, $value);
        }

        if (is_bool($current)) {
            return $current === (bool) $value;
        }

        if (is_array($current) || is_array($value)) {
            return self::canonicalJson($current) === self::canonicalJson($value);
        }

        if (! is_scalar($current) || ! is_scalar($value)) {
            return $current === $value;
        }

        return (string) $current === (string) $value;
    }

    /**
     * A JSON document normalized for comparison: object keys sorted on every
     * level, list order kept (it is content), null read as the empty document.
     */
    private static function canonicalJson(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value ?? [];
        }

        $canonical = [];
        foreach ($value as $key => $item) {
            $canonical[$key] = self::canonicalJson($item);
        }

        ksort($canonical);

        return $canonical;
    }

    private static function isSameInstant(DateTimeInterface $current, mixed $value): bool
    {
        if ($value instanceof DateTimeInterface) {
            return $current->getTimestamp() === $value->getTimestamp();
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return false;
        }

        try {
            // Read the way Eloquent reads a stored value: in the app timezone.
            return $current->getTimestamp() === Carbon::parse($value)->getTimestamp();
        } catch (Exception) {
            // Not a datetime at all: a change, and the caller's to explain when it is written.
            return false;
        }
    }

    private static function hasNumericCast(Game|Campaign $entity, string $field): bool
    {
        $cast = $entity->getCasts()[$field] ?? null;

        return is_string($cast) && (in_array($cast, ['int', 'integer', 'float', 'double', 'real', 'decimal'], true)
            || str_starts_with($cast, 'decimal:'));
    }

    /**
     * The string items of a list, de-duplicated and sorted — a set. A
     * non-array counts as a one-item list (a lone id or flag).
     *
     * @return list<string>
     */
    private static function stringSet(mixed $values): array
    {
        $items = is_array($values) ? $values : [$values];

        $set = [];
        foreach ($items as $item) {
            if (is_string($item) && $item !== '') {
                $set[$item] = true;
            }
        }

        $list = array_keys($set);
        sort($list);

        return $list;
    }

    /**
     * A locale => translation map with empty locales dropped and its keys
     * sorted, so two maps that say the same thing compare equal.
     *
     * @param  array<mixed, mixed>  $map
     * @return array<string, string>
     */
    private static function translationMap(array $map): array
    {
        $normalized = [];
        foreach ($map as $locale => $translation) {
            if (! is_string($locale) || $locale === '' || $translation === null || $translation === '') {
                continue;
            }

            if (! is_string($translation)) {
                throw new InvalidArgumentException(sprintf(
                    'Translation for locale [%s] must be a string, %s was given.',
                    $locale,
                    get_debug_type($translation),
                ));
            }

            $normalized[$locale] = $translation;
        }

        ksort($normalized);

        return $normalized;
    }

    /** The freeform `details` string of a location value, '' when there is none. */
    private static function locationDetails(mixed $location): string
    {
        $details = is_array($location) ? ($location['details'] ?? null) : null;

        return is_string($details) ? $details : '';
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /** The stored capacity as an int — 0 for a null column (the "unlimited" value). */
    private static function currentMaxPlayers(Game|Campaign $entity): int
    {
        $current = $entity->getAttribute('max_players');

        return is_numeric($current) ? (int) $current : 0;
    }

    /** A translation as text: anything that is not a string (a missing one) reads as ''. */
    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * Every approved participant except the owner learns what changed. The
     * fan-out runs after the write committed and can never fail the edit: a
     * dispatch error is logged and the loop ends there.
     *
     * @param  list<string>  $changedFields
     */
    private function notifyParticipants(Game|Campaign $entity, array $changedFields): void
    {
        $isGame = $entity instanceof Game;

        try {
            // EntityUpdated::getActor() reads the owner relation; load it
            // here so a strict-mode model never lazy-loads inside the fan-out.
            $entity->loadMissing('owner');

            $participants = $entity->participants()
                ->where('status', ParticipantStatus::Approved->value)
                ->where('user_id', '!=', $entity->owner_id)
                ->with('user')
                ->get();

            foreach ($participants as $participant) {
                if ($participant->user === null) {
                    continue;
                }

                $this->notifications->send(
                    $participant->user,
                    new EntityUpdated($entity, $changedFields),
                    $isGame ? NotificationCategory::GameUpdated : NotificationCategory::CampaignUpdated,
                );
            }
        } catch (Throwable $e) {
            Log::error(
                $isGame ? 'notification.game_updated_dispatch_failed' : 'notification.campaign_updated_dispatch_failed',
                [
                    $isGame ? 'game_id' : 'campaign_id' => $entity->id,
                    'error' => $e->getMessage(),
                ],
            );
        }
    }
}

<?php

namespace App\Filament\Concerns;

use App\Enums\ActivityType;
use App\Models\Campaign;
use App\Models\Game;
use App\Services\ActivityLogService;
use App\Services\Edit\AgreementEditService;
use App\Support\Edit\ChangedColumns;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The agreement-lock contract of the Filament admin edit pages (EditGame,
 * EditCampaign) — decision D316.
 *
 * Staff are BOUND by the lock on the identity fields — the offered game
 * systems, and for games the game type — with no override: switching which
 * game people are playing is never an operational fix. Every other field
 * stays editable as an operational correction, and each admin edit that
 * changes something is logged with the ADMIN as actor and announced to the
 * approved participants through the owner path's fan-out.
 *
 * A disabled Filament control is bypassable from the client, so the UI
 * disable in the resources is only courtesy: this trait is the guard.
 */
trait BindsAdminEditToAgreementLock
{
    /**
     * Refuse a submitted change to the offered game systems of a locked
     * entity. Nothing is returned: either the systems are the stored set, or
     * a ValidationException names the field under the lock message.
     *
     * The `gameSystems` multi-select is a relationship Select. Filament never
     * puts a multiple relationship Select in the dehydrated form data — it
     * syncs the pivot straight from the RAW form state, and that sync runs
     * inside Schema::getState(), i.e. BEFORE mutateFormDataBeforeSave(). So
     * this reads the raw state, and the field is deliberately NOT ->saved()
     * when disabled (see the resources): a disabled relationship Select that
     * is not saved is never synced, so a forged id list never reaches the
     * pivot. That matters because the admin panel runs without database
     * transactions — a sync done before the refusal could not be rolled back.
     *
     * Call from mutateFormDataBeforeSave(), before anything is written.
     *
     * @param  array<string, mixed>  $rawState  The page's raw form state ({@see rawFormState()})
     *
     * @throws ValidationException When the entity is agreement-locked and the submitted systems are not the stored set.
     */
    protected function refuseChangedGameSystems(array $rawState): void
    {
        $record = $this->getRecord();

        if (! $record instanceof Game && ! $record instanceof Campaign) {
            return;
        }

        if (! $record->isAgreementLocked()) {
            return;
        }

        // Hidden for focused game types: nothing was submitted for it.
        $submitted = $rawState['gameSystems'] ?? null;

        if (! is_array($submitted) || $submitted === []) {
            return;
        }

        $stored = $record->gameSystems()->pluck('game_systems.id')->all();

        if (self::stringSet($submitted) === self::stringSet($stored)) {
            return;
        }

        throw $this->agreementLockedException($record, 'data.gameSystems', ['game_systems']);
    }

    /**
     * The lock refusal for one identity field: logged like every owner-path
     * refusal (`edit.rejected_locked`, plus `surface` so the two paths stay
     * distinguishable), and returned as the entity's lock message keyed by the
     * field's Livewire state path — Filament renders it under the control a
     * staff member tried to change.
     *
     * @param  list<string>  $fields  Machine field names — never values.
     *
     * @throws ValidationException
     */
    protected function agreementLockedException(Game|Campaign $entity, string $errorKey, array $fields): ValidationException
    {
        $isCampaign = $entity instanceof Campaign;

        Log::warning('edit.rejected_locked', [
            'entity_type' => $isCampaign ? 'campaign' : 'game',
            'entity_id' => $entity->id,
            'actor_id' => authenticatedUser()->id,
            'surface' => 'filament_admin',
            // Field NAMES only — an attempted system is not log content.
            'fields' => $fields,
        ]);

        return ValidationException::withMessages([
            $errorKey => $isCampaign
                ? __('campaigns.error_agreement_locked')
                : __('games.error_agreement_locked'),
        ]);
    }

    /**
     * Log an admin edit that changed something, with the admin as actor, and
     * announce it to the approved participants (the owner path's fan-out).
     * Nothing is logged and nobody is notified when nothing changed.
     *
     * Call at the end of afterSave(): Filament has already written the row,
     * and Eloquent keeps that save's changes on the model until then.
     */
    protected function announceAdminEdit(Game|Campaign $record): void
    {
        $changedColumns = ChangedColumns::of($record);

        if ($changedColumns === []) {
            return;
        }

        // The admin is the actor — and this row is what forwards the edit to
        // PostHog as game.updated / campaign.updated through the activity bridge.
        app(ActivityLogService::class)->log(
            $record instanceof Game ? ActivityType::GameUpdated : ActivityType::CampaignUpdated,
            authenticatedUser(),
            $record,
            ['changed_fields' => $changedColumns],
        );

        app(AgreementEditService::class)->notifyParticipants($record, $changedColumns);
    }

    /**
     * The form state exactly as the client submitted it — the source of truth
     * for what a (possibly forged) request tried to change.
     *
     * @return array<string, mixed>
     */
    protected function rawFormState(): array
    {
        $state = $this->form->getRawState();

        return is_array($state) ? $state : [];
    }

    /**
     * The string items of a list, de-duplicated and sorted — a set, so
     * re-ordering the same systems is not a change.
     *
     * @return list<string>
     */
    private static function stringSet(mixed $values): array
    {
        $set = [];

        foreach ((array) $values as $item) {
            if (is_string($item) && $item !== '') {
                $set[$item] = true;
            }
        }

        $list = array_keys($set);
        sort($list);

        return $list;
    }
}

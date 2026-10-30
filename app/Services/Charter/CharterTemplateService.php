<?php

namespace App\Services\Charter;

use App\Dto\CharterContent;
use App\Models\CharterTemplate;
use App\Models\SessionZeroSurvey;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Saved charter templates (M070/S04, R085 — member conveniences).
 *
 * The professional-tooling convenience that rewards membership (concept
 * §4/§5): a GM refines their standard charter once and reuses it across
 * campaigns. saveFromCharter snapshots a charter's normalized content
 * under a name; CharterService::start('template') seeds a new charter
 * from that snapshot.
 *
 * Gates (CharterService pattern): the campaign owner (the
 * manageCharter policy ability — R084/D211 idiom, never GmRoleService;
 * global admins pass via before() like every charter surface) may act on
 * their own charters, and the member seam
 * (CharterMemberService::canUseConveniences) decides who may use
 * conveniences at all — the one place a future pricing milestone can
 * tighten from "active GM role" to "paid membership". Free-tier
 * lifecycle (drafting, publishing, signing) never touches this service.
 */
class CharterTemplateService
{
    /**
     * Save $charter's content as a reusable template named $name for
     * $user. Content is normalized through the tolerant CharterContent
     * normalizer (unknown keys dropped, defaults filled) so template
     * seeds round-trip through CharterService::start('template')
     * unchanged, and stamped with the schema version they were saved
     * under.
     *
     * @throws AuthorizationException when $user does not own the charter's campaign
     * @throws ValidationException when $user cannot use member conveniences or the name fails required/max:255
     */
    public static function saveFromCharter(User $user, SessionZeroSurvey $charter, string $name): CharterTemplate
    {
        $campaign = $charter->campaign;

        if ($campaign === null) {
            throw new AuthorizationException('This charter has no campaign to authorize against.');
        }

        Gate::forUser($user)->authorize('manageCharter', $campaign);

        if (! CharterMemberService::canUseConveniences($user)) {
            throw ValidationException::withMessages([
                'charter' => __('charter.error_templates_member_only'),
            ]);
        }

        $validator = Validator::make(
            ['name' => trim($name)],
            ['name' => ['required', 'string', 'max:255']],
        );
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $template = CharterTemplate::query()->create([
            'user_id' => $user->id,
            'name' => trim($name),
            'content' => CharterContent::fromArray($charter->content ?? [])->toArray(),
            'content_schema' => CharterContent::SCHEMA_VERSION,
        ]);

        Log::info('Charter template saved', [
            'charter_template_id' => $template->id,
            'user_id' => $user->id,
            'survey_id' => $charter->id,
        ]);

        return $template;
    }

    /**
     * The user's saved templates, ordered by name (the start-screen
     * select).
     *
     * @return Collection<int, CharterTemplate>
     */
    public static function listFor(User $user): Collection
    {
        return CharterTemplate::query()
            ->whereBelongsTo($user)
            ->orderBy('name')
            ->get();
    }

    /**
     * Delete a saved template. Owner-gated: another user's template id is
     * refused, never silently deleted.
     *
     * @throws AuthorizationException when $user does not own the template
     */
    public static function delete(User $user, CharterTemplate $template): bool
    {
        if ($template->user_id !== $user->id) {
            throw new AuthorizationException('Only the template owner can delete a template.');
        }

        $deleted = (bool) $template->delete();

        Log::info('Charter template deleted', [
            'charter_template_id' => $template->id,
            'user_id' => $user->id,
        ]);

        return $deleted;
    }
}

<?php

namespace App\Policies;

use App\Enums\CampaignStatus;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\User;
use App\Services\GmMembershipService;
use App\Services\ScopedRoleService;
use App\Traits\ValidatesShortLinkCookie;

class CampaignPolicy
{
    use ValidatesShortLinkCookie;

    /**
     * Global admin bypass.
     */
    public function before(User $user, string $ability): ?bool
    {
        if (app(ScopedRoleService::class)->isGlobalAdmin($user)) {
            return true;
        }

        return null;
    }

    /**
     * View any campaign (Filament resource listing).
     */
    public function viewAny(User $user): bool
    {
        return app(ScopedRoleService::class)->hasPermissionInAnyScope($user, 'view campaign');
    }

    /**
     * View a campaign: public campaigns visible to everyone;
     * protected visible to friends/teammates of the owner, plus participants;
     * private only to owner and participants.
     */
    public function view(?User $user, Campaign $campaign): bool
    {
        if ($campaign->visibility === Visibility::Public) {
            return true;
        }

        // Short link bypass: valid short link grants access unless campaign is completed/cancelled.
        // Terminal-status check also lives inside isValidShortLinkForEntity() via the trait,
        // but we guard here too for defense-in-depth consistency with the share-token path.
        if ($campaign->status !== CampaignStatus::Cancelled
            && $campaign->status !== CampaignStatus::Completed
            && $this->hasValidShortLink($campaign)) {
            return true;
        }

        // Share token bypass: valid token grants access unless campaign is completed/cancelled
        if ($campaign->hasValidShareToken()
            && $campaign->status !== CampaignStatus::Cancelled
            && $campaign->status !== CampaignStatus::Completed) {
            return true;
        }

        if ($campaign->visibility === Visibility::Protected) {
            return $user !== null
                && ((string) $campaign->owner_id === (string) $user->id
                    || ($campaign->owner && $user->isFriendOrTeammate($campaign->owner))
                    || $campaign->participants()->whereBelongsTo($user)->exists());
        }

        // Private campaigns require auth and ownership (or participation)
        return $user !== null
            && ((string) $campaign->owner_id === (string) $user->id
                || $campaign->participants()->whereBelongsTo($user)->exists());
    }

    /**
     * View the campaign journal: the owner or a seated participant
     * (approved or benched) — the member gate behind the Journal tab.
     *
     * Deliberately stricter than view(), which admits any authenticated
     * user to public campaigns (plus short-link/share-token bypasses):
     * view() is NEVER consulted for journal content — pending/waitlisted
     * applicants and strangers must not read the journal even on public
     * campaigns. Global admins pass via before(), consistent with the
     * other abilities.
     */
    public function viewJournal(User $user, Campaign $campaign): bool
    {
        if ((string) $campaign->owner_id === (string) $user->id) {
            return true;
        }

        return $campaign->participants()
            ->whereBelongsTo($user)
            ->whereIn('status', [
                ParticipantStatus::Approved->value,
                ParticipantStatus::Benched->value,
            ])
            ->exists();
    }

    /**
     * Manage Previously On (review and one-click-publish the
     * auto-composed pre-session digest, M071/S04): the campaign owner
     * who is an active GM member — the D185 gate, resolved through the
     * D223 membership-capability seam so Previously On and charter
     * conveniences always tighten together. Global admins pass via
     * before(), consistent with the other abilities.
     */
    public function managePreviouslyOn(User $user, Campaign $campaign): bool
    {
        return (string) $campaign->owner_id === (string) $user->id
            && GmMembershipService::canUseMemberFeatures($user);
    }

    /**
     * Manage the recruitment summary (M071/S05, R082): the owner only.
     *
     * Deliberately NO GmMembershipService member gate, unlike
     * managePreviouslyOn — R082 is a free feature, so every campaign GM
     * can compose the spoiler-safe "Campaign so far" block for their
     * public page. Also deliberately NOT viewJournal: seated
     * participants may read the journal but must never publish blocks.
     * Global admins pass via before(), consistent with the other
     * abilities.
     */
    public function manageRecruitmentSummary(User $user, Campaign $campaign): bool
    {
        return (string) $campaign->owner_id === (string) $user->id;
    }

    /**
     * Manage the campaign's charter lifecycle (start/author, publish,
     * amend, archive — M070): the campaign owner only.
     *
     * Deliberately NOT membership-gated (R084: charter authoring is
     * free-tier safety, never a GmMembershipService check) and deliberately
     * a Policy ability rather than scattered owner_id comparisons — every
     * charter mutation surface (CharterComposer mount, CharterAudit mount,
     * and CharterService's per-mutation re-authorization) resolves through
     * this one rule. Global admins pass via before(), consistent with the
     * other abilities.
     */
    public function manageCharter(User $user, Campaign $campaign): bool
    {
        return (string) $campaign->owner_id === (string) $user->id;
    }

    /**
     * Create a campaign: any authenticated user with a complete profile.
     * Admin roles (Games Admin, Platform Admin) are already handled by before().
     */
    public function create(User $user): bool
    {
        return (bool) $user->profile_complete;
    }

    /**
     * Update campaign details: owner OR scoped admin.
     */
    public function update(User $user, Campaign $campaign): bool
    {
        if ((string) $campaign->owner_id === (string) $user->id) {
            return true;
        }

        return $this->checkPermission($user, 'update campaign');
    }

    /**
     * Delete a campaign: owner OR scoped admin.
     */
    public function delete(User $user, Campaign $campaign): bool
    {
        if ((string) $campaign->owner_id === (string) $user->id) {
            return true;
        }

        return $this->checkPermission($user, 'delete campaign');
    }

    /**
     * Manage the share link (generate/revoke/regenerate): the owner.
     *
     * Note: before() grants global admins bypass here too — consistent with
     * update/delete, and harmless (admins can already write the share_token
     * columns through admin surfaces).
     */
    public function manageShareToken(User $user, Campaign $campaign): bool
    {
        return (string) $campaign->owner_id === (string) $user->id;
    }

    /**
     * Leave the campaign: an active participant (approved, waitlisted,
     * benched, or pending) who is not the owner. CampaignDetail::leaveCampaign
     * and CampaignsPage::leaveCampaign both authorize through this ability so
     * the rule is defined exactly once.
     */
    public function leave(User $user, Campaign $campaign): bool
    {
        if ((string) $campaign->owner_id === (string) $user->id) {
            return false;
        }

        return $campaign->participants()
            ->whereBelongsTo($user)
            ->whereIn('status', [
                ParticipantStatus::Approved->value,
                ParticipantStatus::Waitlisted->value,
                ParticipantStatus::Benched->value,
                ParticipantStatus::Pending->value,
            ])
            ->exists();
    }

    private function checkPermission(User $user, string $permission): bool
    {
        return app(ScopedRoleService::class)->checkPermission($user, $permission);
    }

    /**
     * Check if the current request carries a valid short link for this campaign.
     */
    private function hasValidShortLink(Campaign $campaign): bool
    {
        return $this->isValidShortLinkForEntity($campaign);
    }
}

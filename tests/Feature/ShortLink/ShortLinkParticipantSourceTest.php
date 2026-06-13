<?php

use App\Enums\JoinSource;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\ShortLink;
use App\Models\User;

beforeEach(function () {
    $this->gameSystem = GameSystem::factory()->create();
    $this->owner = User::factory()->create();
    $this->player = User::factory()->create();
});

// ── source_label accessor ─────────────────────────────────

describe('source_label accessor', function () {
    it('returns short link label when participant has short_link_id', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $link = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $this->owner->id,
            'label' => 'Discord Promo',
        ]);

        $participant = GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $this->player->id,
            'short_link_id' => $link->id,
            'join_source' => JoinSource::ShortLink,
        ]);

        expect($participant->source_label)->toBe('Discord Promo');
    });

    it('returns short link code when link has no label', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $link = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $this->owner->id,
            'label' => null,
            'code' => 'ABC1234',
        ]);

        $participant = GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $this->player->id,
            'short_link_id' => $link->id,
            'join_source' => JoinSource::ShortLink,
        ]);

        expect($participant->source_label)->toBe('ABC1234');
    });

    it('returns join_source enum label when no short_link_id', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $participant = GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $this->player->id,
            'short_link_id' => null,
            'join_source' => JoinSource::FriendInvite,
        ]);

        expect($participant->source_label)->toBe('Friend Invite');
    });

    it('returns join_source label for ShareLink enum', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $participant = GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $this->player->id,
            'short_link_id' => null,
            'join_source' => JoinSource::ShareLink,
        ]);

        expect($participant->source_label)->toBe('Share Link');
    });

    it('returns null when join_source is null and no short_link_id', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $participant = GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $this->player->id,
            'short_link_id' => null,
            'join_source' => null,
        ]);

        expect($participant->source_label)->toBeNull();
    });

    it('prefers short link label over join_source when both are set', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $link = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $this->owner->id,
            'label' => 'Twitter Campaign',
        ]);

        // join_source is FriendInvite but short_link_id is set — short link takes priority
        $participant = GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $this->player->id,
            'short_link_id' => $link->id,
            'join_source' => JoinSource::FriendInvite,
        ]);

        expect($participant->source_label)->toBe('Twitter Campaign');
    });
});

// ── Campaign participant source_label ──────────────────────

describe('Campaign participant source_label', function () {
    it('returns short link label for campaign participants', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $link = ShortLink::factory()->create([
            'linkable_type' => Campaign::class,
            'linkable_id' => $campaign->id,
            'user_id' => $this->owner->id,
            'label' => 'Reddit Post',
        ]);

        $participant = CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $this->player->id,
            'short_link_id' => $link->id,
            'join_source' => JoinSource::ShortLink,
        ]);

        expect($participant->source_label)->toBe('Reddit Post');
    });

    it('returns join_source enum label for campaign participants without short link', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $participant = CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $this->player->id,
            'short_link_id' => null,
            'join_source' => JoinSource::Application,
        ]);

        expect($participant->source_label)->toBe('Application');
    });
});

// ── shortLink relationship ─────────────────────────────────

describe('shortLink relationship', function () {
    it('game participant belongs to short link', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $link = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $this->owner->id,
        ]);

        $participant = GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $this->player->id,
            'short_link_id' => $link->id,
        ]);

        expect($participant->shortLink)->not->toBeNull();
        expect($participant->shortLink->id)->toBe($link->id);
    });

    it('campaign participant belongs to short link', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $link = ShortLink::factory()->create([
            'linkable_type' => Campaign::class,
            'linkable_id' => $campaign->id,
            'user_id' => $this->owner->id,
        ]);

        $participant = CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $this->player->id,
            'short_link_id' => $link->id,
        ]);

        expect($participant->shortLink)->not->toBeNull();
        expect($participant->shortLink->id)->toBe($link->id);
    });

    it('returns null when no short link is associated', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $participant = GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $this->player->id,
            'short_link_id' => null,
        ]);

        expect($participant->shortLink)->toBeNull();
    });
});

// ── source_label with trashed short link ───────────────────

describe('source_label edge cases', function () {
    it('falls back to join_source when short link has been soft-deleted', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $link = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $this->owner->id,
            'label' => 'Deleted Link',
        ]);

        $participant = GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $this->player->id,
            'short_link_id' => $link->id,
            'join_source' => JoinSource::ShortLink,
        ]);

        // Soft-delete the link
        $link->delete();

        // Refresh participant — relationship should return null since link is trashed
        $participant->refresh();
        // The relationship doesn't use withTrashed by default, so it returns null
        // and the accessor should fall back to join_source
        expect($participant->source_label)->toBe('Short Link');
    });
});

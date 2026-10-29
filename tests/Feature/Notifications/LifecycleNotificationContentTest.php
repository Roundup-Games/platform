<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use App\Notifications\DebriefingAvailable;
use App\Notifications\PlayerBenched;
use App\Notifications\PromotedFromBench;
use App\Notifications\WaitlistPlaced;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

// Content-shape pins for the four lifecycle notifications whose compositions
// carry non-trivial shape but had no direct coverage after the S04 envelope
// conversion (envelope helpers are tested; these composed outputs were not).
// Per K004, assertions compare against the translation helper output — never
// literal lang/ values.

beforeEach(function () {
    app()->setLocale('en');
});

function contentUser(): User
{
    return User::factory()->create(['preferred_language' => 'en']);
}

function contentGame(): Game
{
    $system = GameSystem::factory()->create();

    return Game::factory()->create([
        'game_system_id' => $system->id,
        'campaign_id' => null,
    ]);
}

function contentCampaign(): Campaign
{
    return Campaign::factory()->create();
}

/**
 * The (unsigned) path of an unsubscribe footer link — the rendered mail
 * carries the signed variant of exactly this path.
 */
function unsubscribePath(User $user, string $category): string
{
    return route('notifications.unsubscribe', [
        'locale' => 'en',
        'user' => $user->id,
        'category' => $category,
    ], false);
}

// ════════════════════════════════════════════════════
// WaitlistPlaced — informational placement, waitlist_placement category
// ════════════════════════════════════════════════════

describe('WaitlistPlaced content', function () {
    it('composes mail with the placement subject, body, view action, and unsubscribe line', function () {
        $user = contentUser();
        $game = contentGame();
        $notification = new WaitlistPlaced($game);

        $mail = $notification->toMail($user);
        expect($mail->subject)->toBe(__('notifications.subject_waitlist_placed', ['game' => $game->name]));

        $rendered = (string) $mail->render();
        // The body line carries **markdown** entity emphasis; strip_tags +
        // de-markdown keeps the pin wording-agnostic to the strong-tag split.
        $text = strip_tags($rendered);
        expect($text)->toContain(str_replace('**', '', __('notifications.body_waitlist_placed', ['game' => $game->name])))
            ->and($rendered)->toContain(__('notifications.action_view_game'))
            ->and($rendered)->toContain(unsubscribePath($user, 'waitlist_placement'));
    });

    it('carries the entity identity in the database payload', function () {
        $game = contentGame();
        $data = (new WaitlistPlaced($game))->toDatabase(contentUser());

        expect($data['type'])->toBe('waitlist_placed')
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_id'])->toBe($game->id)
            ->and($data['entity_name'])->toBe($game->name)
            ->and($data['action_url'])->toBe(route('games.show', ['locale' => 'en', 'id' => $game]));
    });

    it('mirrors the push title/body into the discord embed', function () {
        $user = contentUser();
        $game = contentGame();

        $push = (new WaitlistPlaced($game))->toPush($user);
        $embed = (new WaitlistPlaced($game))->toDiscord($user)->embeds[0];

        expect($push->title)->toBe(__('notifications.push_title_waitlist_placed'))
            ->and($push->body)->toBe(__('notifications.push_body_waitlist_placed', ['game' => $game->name]))
            ->and($push->tag)->toBe("waitlist-placed-game-{$game->id}")
            ->and($embed['title'])->toBe($push->title)
            ->and($embed['description'])->toBe($push->body)
            ->and($embed['url'])->toBe($push->url);
    });
});

// ════════════════════════════════════════════════════
// PlayerBenched — bench placement distinguishes the entity type
// ════════════════════════════════════════════════════

describe('PlayerBenched content', function () {
    it('composes mail and database payload for a game entity', function () {
        $user = contentUser();
        $game = contentGame();
        $notification = new PlayerBenched($game, 'game');

        $mail = $notification->toMail($user);
        expect($mail->subject)->toBe(__('notifications.subject_player_benched', ['entity' => $game->name]));

        $rendered = (string) $mail->render();
        $text = strip_tags($rendered);
        expect($text)->toContain(str_replace('**', '', __('notifications.body_player_benched', ['entity' => $game->name])))
            ->and($rendered)->toContain(unsubscribePath($user, 'player_benched'));

        $data = $notification->toDatabase($user);
        expect($data['type'])->toBe('player_benched')
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_id'])->toBe($game->id)
            ->and($data['entity_name'])->toBe($game->name);
    });

    it('mirrors the push composition into the discord embed', function () {
        $user = contentUser();
        $game = contentGame();

        $push = (new PlayerBenched($game, 'game'))->toPush($user);
        $embed = (new PlayerBenched($game, 'game'))->toDiscord($user)->embeds[0];

        expect($push->title)->toBe(__('common.content_you_are_on_the_bench'))
            ->and($push->body)->toBe(__('notifications.subject_player_benched', ['entity' => $game->name]))
            ->and($push->tag)->toBe("player-benched-game-{$game->id}")
            ->and($embed['title'])->toBe($push->title)
            ->and($embed['description'])->toBe($push->body)
            ->and($embed['url'])->toBe($push->url);
    });
});

// ════════════════════════════════════════════════════
// PromotedFromBench — seat regained, bench_updates category
// ════════════════════════════════════════════════════

describe('PromotedFromBench content', function () {
    it('composes mail with the promotion subject and bench_updates unsubscribe line', function () {
        $user = contentUser();
        $campaign = contentCampaign();
        $notification = new PromotedFromBench($campaign);

        $mail = $notification->toMail($user);
        expect($mail->subject)->toBe(__('notifications.subject_promoted_from_bench', ['game' => $campaign->name]));

        $rendered = (string) $mail->render();
        $text = strip_tags($rendered);
        expect($text)->toContain(str_replace('**', '', __('notifications.body_promoted_from_bench', ['game' => $campaign->name])))
            ->and($rendered)->toContain(__('notifications.action_view_campaign'))
            ->and($rendered)->toContain(unsubscribePath($user, 'bench_updates'));
    });

    it('carries the entity identity in the database payload', function () {
        $campaign = contentCampaign();
        $data = (new PromotedFromBench($campaign))->toDatabase(contentUser());

        expect($data['type'])->toBe('promoted_from_bench')
            ->and($data['entity_type'])->toBe('campaign')
            ->and($data['entity_id'])->toBe($campaign->id)
            ->and($data['entity_name'])->toBe($campaign->name)
            ->and($data['action_url'])->toBe(route('campaigns.show', ['locale' => 'en', 'id' => $campaign]));
    });

    it('mirrors the push title/body into the discord embed', function () {
        $user = contentUser();
        $campaign = contentCampaign();

        $push = (new PromotedFromBench($campaign))->toPush($user);
        $embed = (new PromotedFromBench($campaign))->toDiscord($user)->embeds[0];

        expect($push->title)->toBe(__('notifications.push_title_promoted_from_bench'))
            ->and($push->body)->toBe(__('notifications.push_body_promoted_from_bench', ['game' => $campaign->name]))
            ->and($push->tag)->toBe("promoted-from-bench-campaign-{$campaign->id}")
            ->and($embed['title'])->toBe($push->title)
            ->and($embed['description'])->toBe($push->body)
            ->and($embed['url'])->toBe($push->url);
    });
});

// ════════════════════════════════════════════════════
// DebriefingAvailable — post-game nudge, game-only entity
// ════════════════════════════════════════════════════

describe('DebriefingAvailable content', function () {
    it('composes mail with the debriefing subject, submit action, and unsubscribe line', function () {
        $user = contentUser();
        $game = contentGame();
        $notification = new DebriefingAvailable($game);

        $mail = $notification->toMail($user);
        expect($mail->subject)->toBe(__('notifications.subject_debriefing_available', ['game' => $game->name]));

        $rendered = (string) $mail->render();
        $text = strip_tags($rendered);
        expect($text)->toContain(str_replace('**', '', __('notifications.body_debriefing_available', ['game' => $game->name])))
            ->and($rendered)->toContain(__('games.action_submit_debriefing'))
            ->and($rendered)->toContain(unsubscribePath($user, 'debriefing_available'));
    });

    it('carries the entity identity in the database payload', function () {
        $game = contentGame();
        $data = (new DebriefingAvailable($game))->toDatabase(contentUser());

        expect($data['type'])->toBe('debriefing_available')
            ->and($data['entity_type'])->toBe('game')
            ->and($data['entity_id'])->toBe($game->id)
            ->and($data['entity_name'])->toBe($game->name)
            ->and($data['action_url'])->toBe(route('games.show', ['locale' => 'en', 'id' => $game]));
    });

    it('mirrors the push composition into the discord embed', function () {
        $user = contentUser();
        $game = contentGame();

        $push = (new DebriefingAvailable($game))->toPush($user);
        expect($push)->not->toBeNull();

        $embed = (new DebriefingAvailable($game))->toDiscord($user)->embeds[0];

        expect($push->title)->toBe(__('notifications.push_title_debriefing_available'))
            ->and($push->body)->toBe(__('notifications.subject_debriefing_available', ['game' => $game->name]))
            ->and($push->tag)->toBe("debriefing-{$game->id}")
            ->and($embed['title'])->toBe($push->title)
            ->and($embed['description'])->toBe($push->body)
            ->and($embed['url'])->toBe($push->url);
    });
});

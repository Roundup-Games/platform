<?php

use App\Models\Campaign;
use App\Models\CampaignJournalEntry;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use App\Notifications\DebriefingAvailable;
use App\Notifications\EntityInvitation;
use App\Notifications\EntityUpdated;
use App\Notifications\PlayerBenched;
use App\Notifications\PromotedFromBench;
use App\Notifications\RecapPosted;
use App\Notifications\SeatDemoted;
use App\Notifications\SessionAddedToCampaign;
use App\Notifications\WaitlistPlaced;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;

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
            // The Discord embed keeps the direct route; the push url is the
            // signed read-marker wrapping it (D288 item 1) — same landing,
            // different channel duties.
            ->and($embed['url'])->toBe(route('games.show', ['locale' => 'en', 'id' => $game]))
            ->and($push->url)->toContain('/notifications/read-marker/');
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
            ->and($rendered)->toContain(unsubscribePath($user, 'bench_updates'));

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
            ->and($embed['url'])->toBe(route('games.show', ['locale' => 'en', 'id' => $game]))
            ->and($push->url)->toContain('/notifications/read-marker/');
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
            ->and($embed['url'])->toBe(route('campaigns.show', ['locale' => 'en', 'id' => $campaign]))
            ->and($push->url)->toContain('/notifications/read-marker/');
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
            ->and($rendered)->toContain(unsubscribePath($user, 'session_content'));
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
            ->and($embed['url'])->toBe(route('games.show', ['locale' => 'en', 'id' => $game]))
            ->and($push->url)->toContain('/notifications/read-marker/');
    });
});

// ════════════════════════════════════════════════════
// EntityUpdated — update mails scope the subject with what changed (M080/S04/T03)
// ════════════════════════════════════════════════════

describe('EntityUpdated content', function () {
    it('composes a game mail whose subject fronts the game and scopes it with the change summary', function () {
        $user = contentUser();
        $game = contentGame();
        $summary = __('common.field_location');
        $notification = new EntityUpdated($game, ['location_id']);

        $mail = $notification->toMail($user);
        expect($mail->subject)
            ->toBe(__('notifications.subject_game_updated', ['game' => $game->name, 'summary' => $summary]))
            ->and($mail->subject)->toStartWith($game->name)
            ->and($mail->subject)->toContain($summary);

        $rendered = (string) $mail->render();
        $text = strip_tags($rendered);
        expect($text)->toContain(str_replace('**', '', __('notifications.body_game_updated', ['game' => $game->name, 'fields' => $summary])))
            ->and($rendered)->toContain(__('notifications.action_view_game'))
            ->and($rendered)->toContain(unsubscribePath($user, 'game_updated'));
    });

    it('composes a campaign mail whose subject fronts the campaign and scopes it with the change summary', function () {
        $user = contentUser();
        $campaign = contentCampaign();
        $summary = __('common.field_name');
        $notification = new EntityUpdated($campaign, ['name']);

        $mail = $notification->toMail($user);
        expect($mail->subject)
            ->toBe(__('notifications.subject_campaign_updated', ['campaign' => $campaign->name, 'summary' => $summary]))
            ->and($mail->subject)->toStartWith($campaign->name)
            ->and($mail->subject)->toContain($summary)
            ->and((string) $mail->render())->toContain(__('notifications.action_view_campaign'))
            ->and((string) $mail->render())->toContain(unsubscribePath($user, 'campaign_updated'));
    });

    it('renders the whole mail in the recipient locale, not the app session locale', function () {
        app()->setLocale('en');
        $user = User::factory()->create(['preferred_language' => 'de']);
        $game = contentGame();
        $summary = __('common.field_location', [], 'de');

        $mail = (new EntityUpdated($game, ['location_id']))->toMail($user);

        expect($mail->subject)
            ->toBe(__('notifications.subject_game_updated', ['game' => $game->name, 'summary' => $summary], 'de'))
            ->and($mail->subject)->toStartWith($game->name)
            ->and($mail->subject)->toContain($summary);
    });

    it('names the new venue instead of the venue column when the venue moved', function () {
        $user = contentUser();
        $game = contentGame();
        $venue = Location::factory()->create(['name' => 'Cafe Nord', 'city' => 'Berlin']);
        $game->fill(['location_id' => $venue->id])->save();

        $mail = (new EntityUpdated($game->fresh(), ['location_id']))->toMail($user);
        $rendered = (string) $mail->render();

        expect($rendered)->toContain(__('notifications.body_venue_now', ['venue' => 'Cafe Nord']))
            ->and($rendered)->not->toContain('location_id');
    });

    it('never echoes an unmapped column name into the mail', function () {
        $user = contentUser();
        $game = contentGame();

        $mail = (new EntityUpdated($game, ['some_future_column']))->toMail($user);

        expect((string) $mail->render())->not->toContain('some_future_column')
            ->and($mail->subject)->toContain(__('common.field_details'));
    });
});

// ════════════════════════════════════════════════════
// SessionAddedToCampaign — Game-framed view action (M080/S04/T03)
// ════════════════════════════════════════════════════

describe('SessionAddedToCampaign content', function () {
    it('composes mail with the campaign subject and the Game-framed view action', function () {
        $user = contentUser();
        $game = contentGame();
        $campaign = contentCampaign();
        $notification = new SessionAddedToCampaign($game, $campaign);

        $mail = $notification->toMail($user);
        expect($mail->subject)
            ->toBe(__('notifications.subject_session_added_to_campaign', ['campaign' => $campaign->name]));

        $rendered = (string) $mail->render();
        $text = strip_tags($rendered);
        expect($text)->toContain(str_replace('**', '', __('notifications.body_session_added_to_campaign', ['campaign' => $campaign->name])))
            ->and($rendered)->toContain(__('notifications.action_session_added_to_campaign'))
            ->and($rendered)->toContain(unsubscribePath($user, 'session_added_to_campaign'))
            ->and($mail->actionUrl)->toContain('/notifications/read-marker/')
            ->and(rawurldecode($mail->actionUrl))->toContain('/en/dashboard/games/'.$game->id);
    });
});

// ════════════════════════════════════════════════════
// SeatDemoted — host-action voice in the German mail (M080/S04/T03)
// ════════════════════════════════════════════════════

describe('SeatDemoted content', function () {
    it('composes the English mail with the reclaimed-seat subject and unsubscribe line', function () {
        $user = contentUser();
        $game = contentGame();
        $mail = (new SeatDemoted($game, 'roster reduced'))->toMail($user);

        expect($mail->subject)->toBe(__('notifications.subject_seat_demoted', ['entity' => $game->name]))
            ->and((string) $mail->render())->toContain(unsubscribePath($user, 'seat_demoted'));
    });

    it('phrases the German mail as a host action, never a self-relinquished seat', function () {
        app()->setLocale('de');
        $user = contentUser();
        $game = contentGame();
        $mail = (new SeatDemoted($game, 'Kader verkleinert'))->toMail($user);

        expect($mail->subject)->toBe(__('notifications.subject_seat_demoted', ['entity' => $game->name], 'de'))
            ->and($mail->subject)->not->toContain('abgegeben')
            ->and((string) $mail->render())->not->toContain('abgegeben');
    });
});

// ══════════════════════════════════════════════════
// EntityInvitation — subject fronts the game; body answers when/where
// ══════════════════════════════════════════════════

describe('EntityInvitation content', function () {
    it('fronts the game in the subject and answers when and where in the body', function () {
        $user = contentUser();
        $inviter = User::factory()->create(['name' => 'Host Probe']);
        $system = GameSystem::factory()->create();
        $venue = Location::factory()->create(['name' => 'Cafe Mitte', 'city' => 'Berlin']);
        $game = Game::factory()->create([
            'game_system_id' => $system->id,
            'campaign_id' => null,
            'location_id' => $venue->id,
        ]);

        $mail = (new EntityInvitation($game, $inviter))->toMail($user);

        expect($mail->subject)
            ->toBe(__('notifications.subject_game_invitation', ['inviter' => $inviter->name, 'game' => $game->name]))
            ->and($mail->subject)->toContain($game->name);

        $text = strip_tags((string) $mail->render());
        expect($text)->toContain(e(__('common.field_date_time')))
            ->and($text)->toContain(format_date($game->date_time, 'datetime', $user->timezone ?? community_timezone()))
            ->and($text)->toContain(e(__('common.field_location')))
            ->and($text)->toContain('Cafe Mitte');
    });

    it('keeps campaign invitations date-free — a campaign has no single date', function () {
        $user = contentUser();
        $inviter = User::factory()->create(['name' => 'Host Probe']);
        $campaign = contentCampaign();

        $mail = (new EntityInvitation($campaign, $inviter))->toMail($user);

        $text = strip_tags((string) $mail->render());
        expect($mail->subject)
            ->toBe(__('notifications.subject_campaign_invitation', ['inviter' => $inviter->name, 'campaign' => $campaign->name]))
            ->and($text)->not->toContain(e(__('common.field_date_time')));
    });
});

// ── SessionAddedToCampaign: the date is the news ──

describe('SessionAddedToCampaign content', function () {
    it('puts the session date in the body in the recipient\'s wall clock', function () {
        $user = contentUser();
        $game = contentGame();
        $campaign = contentCampaign();

        $text = strip_tags((string) (new SessionAddedToCampaign($game, $campaign))->toMail($user)->render());

        expect($text)->toContain(e(__('common.field_date_time')))
            ->and($text)->toContain(format_date($game->date_time, 'datetime', $user->timezone ?? community_timezone()));
    });
});

// ── RecapPosted: quotes the recap, like BulletinPosted quotes the bulletin ──

describe('RecapPosted content', function () {
    it('quotes a snippet of the recap in the body', function () {
        $user = contentUser();
        $author = User::factory()->create(['name' => 'Host Probe']);
        $system = GameSystem::factory()->create();
        $campaign = contentCampaign();
        $game = Game::factory()->create([
            'game_system_id' => $system->id,
            'campaign_id' => $campaign->id,
        ]);
        // The canonical session-recap fixture (factory state fills the
        // NOT NULL columns the relation create would miss).
        CampaignJournalEntry::factory()
            ->forCampaignSession($game)
            ->create([
                'author_user_id' => $author->id,
                'body' => 'The party finally confronted the baron and the dice were merciless.',
            ]);

        $text = strip_tags((string) (new RecapPosted($game->fresh(), $author))->toMail($user)->render());

        expect($text)->toContain(__('notifications.body_recap_content', [
            'content' => Str::limit('The party finally confronted the baron and the dice were merciless.', 100),
        ]));
    });
});

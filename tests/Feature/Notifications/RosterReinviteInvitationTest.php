<?php

use App\Enums\NotificationCategory;
use App\Models\Game;
use App\Models\User;
use App\Models\UserRelationship;
use App\Notifications\RosterReinviteInvitation;
use App\Services\Discord\DiscordPalette;
use App\Services\NotificationQueryService;
use App\Services\NotificationService;

beforeEach(function () {
    app()->setLocale('en');
});

/**
 * A replacement game linked to its cancelled predecessor, the host who set it
 * up, and the former participant the re-invite goes to.
 *
 * @return array{game: Game, replaced: Game, host: User, participant: User}
 */
function rosterReinviteContext(): array
{
    $host = User::factory()->create(['name' => 'Host']);
    $replaced = Game::factory()->create(['owner_id' => $host->id, 'name' => ['en' => 'Cancelled game']]);
    $game = Game::factory()->create([
        'owner_id' => $host->id,
        'name' => ['en' => 'Replacement game'],
        'recreates_game_id' => $replaced->id,
    ]);
    $participant = User::factory()->create(['profile_complete' => true]);

    return ['game' => $game, 'replaced' => $replaced, 'host' => $host, 'participant' => $participant];
}

/**
 * The body copy as it reads once markdown bold is rendered away — the mail
 * HTML is compared after tag stripping, so the ** markers never reach it.
 *
 * @param  array<string, string>  $replace
 */
function rosterReinviteBody(string $key, array $replace, string $locale = 'en'): string
{
    return str_replace('**', '', __($key, $replace, $locale));
}

it('states the replaced game and lists every changed field in the mail', function () {
    $ctx = rosterReinviteContext();
    $replace = ['inviter' => 'Host', 'game' => 'Replacement game', 'replaced' => 'Cancelled game'];

    $mail = (new RosterReinviteInvitation($ctx['game'], $ctx['host'], $ctx['replaced']->name, ['date_time', 'location_id']))
        ->toMail($ctx['participant']);
    $html = (string) $mail->render();

    expect($mail->subject)->toBe(__('notifications.subject_roster_reinvite', ['game' => 'Replacement game']));
    expect(strip_tags($html))->toContain(rosterReinviteBody('notifications.body_roster_reinvite', $replace));
    expect($html)->toContain(e(__('notifications.content_roster_reinvite_changed_heading')));
    expect($html)->toContain(e(__('common.field_date_time')));
    expect($html)->toContain(e(__('common.field_location')));
    expect($html)->toContain(e(__('notifications.action_roster_reinvite_view')));
    expect($mail->viewData['preheader'])->toBe(__('notifications.preheader_roster_reinvite'));
    // Machine column names never reach the reader — only their labels do.
    expect($html)->not->toContain('date_time');
});

it('never shows a schema column name for a field without a label', function () {
    $ctx = rosterReinviteContext();

    $html = (string) (new RosterReinviteInvitation($ctx['game'], $ctx['host'], $ctx['replaced']->name, ['internal_flag']))
        ->toMail($ctx['participant'])->render();

    expect($html)->toContain(e(__('common.field_details')));
    expect($html)->not->toContain('internal_flag');
});

it('does not announce changes when no field differs', function () {
    $ctx = rosterReinviteContext();

    $mail = (new RosterReinviteInvitation($ctx['game'], $ctx['host'], $ctx['replaced']->name, []))
        ->toMail($ctx['participant']);
    $html = (string) $mail->render();

    expect(strip_tags($html))->toContain(rosterReinviteBody('notifications.body_roster_reinvite_unchanged', [
        'inviter' => 'Host', 'game' => 'Replacement game', 'replaced' => 'Cancelled game',
    ]));
    expect($html)->not->toContain(__('notifications.content_roster_reinvite_changed_heading'));
    expect($mail->viewData['preheader'])->toBe(__('notifications.preheader_roster_reinvite_unchanged'));
});

it('escapes the game names in the mail', function () {
    $ctx = rosterReinviteContext();

    $html = (string) (new RosterReinviteInvitation($ctx['game'], $ctx['host'], "<script>alert('x')</script>", ['date_time']))
        ->toMail($ctx['participant'])->render();

    expect($html)->toContain('&lt;script&gt;');
    expect($html)->not->toContain("<script>alert('x')</script>");
});

it('stores the replaced game, the diff and the inviter in the bell payload', function () {
    $ctx = rosterReinviteContext();

    $data = (new RosterReinviteInvitation($ctx['game'], $ctx['host'], $ctx['replaced']->name, ['date_time', 'price']))
        ->toDatabase($ctx['participant']);

    expect($data)->toBe([
        'type' => 'game_invitation',
        'entity_type' => 'game',
        'entity_id' => $ctx['game']->id,
        'entity_name' => 'Replacement game',
        'inviter_id' => $ctx['host']->id,
        'inviter_name' => 'Host',
        'replaces_game_id' => $ctx['replaced']->id,
        'replaces_game_name' => 'Cancelled game',
        'changed_fields' => ['date_time', 'price'],
        'action_url' => route('games.show', ['locale' => 'en', 'id' => $ctx['game']->id]),
    ]);
});

it('lands on the bell as a game invitation naming the host and the replacement', function () {
    $ctx = rosterReinviteContext();
    $notification = new RosterReinviteInvitation($ctx['game'], $ctx['host'], $ctx['replaced']->name, ['date_time']);

    app(NotificationService::class)->send($ctx['participant'], $notification, NotificationCategory::GameInvitation);

    $group = (new NotificationQueryService)->getGroupedForUser($ctx['participant']->fresh())->first();

    expect($notification->getActor())->toBe($ctx['host']);
    expect($group->type)->toBe('RosterReinviteInvitation');
    expect($group->category)->toBe(NotificationCategory::GameInvitation->value);
    expect($group->actorNames)->toBe(['Host']);
    expect($group->actorIds)->toBe([$ctx['host']->id]);
    expect($group->actionUrl)->toBe(route('games.show', ['locale' => 'en', 'id' => $ctx['game']->id]));
    expect($group->displayString)->toBe(__('notifications.display_one_actor', [
        'actor' => 'Host',
        'verb' => __('notifications.verb_game_invitation'),
    ]));
    expect($group->displayHtml)->toContain('Replacement game');
});

it('is suppressed when the recipient has blocked the host', function () {
    $ctx = rosterReinviteContext();
    UserRelationship::block($ctx['participant'], $ctx['host']);

    app(NotificationService::class)->send(
        $ctx['participant'],
        new RosterReinviteInvitation($ctx['game'], $ctx['host'], $ctx['replaced']->name, ['date_time']),
        NotificationCategory::GameInvitation,
    );

    expect($ctx['participant']->fresh()->notifications)->toHaveCount(0);
});

it('mirrors the invitation to push and Discord', function () {
    $ctx = rosterReinviteContext();
    $notification = new RosterReinviteInvitation($ctx['game'], $ctx['host'], $ctx['replaced']->name, ['date_time', 'price']);

    $push = $notification->toPush($ctx['participant']);

    expect($push->title)->toBe(__('notifications.category_game_invitation'));
    expect($push->body)->toBe(__('notifications.subject_roster_reinvite', ['game' => 'Replacement game']));
    expect($push->url)->toContain('/notifications/read-marker/');

    $embed = $notification->toDiscord($ctx['participant'])->embeds[0];

    expect($embed['title'])->toBe(__('notifications.subject_roster_reinvite', ['game' => 'Replacement game']));
    expect($embed['url'])->toBe(route('games.show', ['locale' => 'en', 'id' => $ctx['game']->id]));
    expect($embed['description'])->toBe(implode("\n\n", [
        __('notifications.body_roster_reinvite', ['inviter' => 'Host', 'game' => 'Replacement game', 'replaced' => 'Cancelled game']),
        implode("\n", [
            '**'.__('notifications.content_roster_reinvite_changed_heading').'**',
            '- '.__('common.field_date_time'),
            '- '.__('billing.content_price_eur'),
        ]),
    ]));
    expect($embed['color'])->toBe(DiscordPalette::ACTION_NEEDED);
});

it('renders in German with every copy key translated', function () {
    app()->setLocale('de');
    $ctx = rosterReinviteContext();

    $notification = new RosterReinviteInvitation($ctx['game'], $ctx['host'], $ctx['replaced']->name, ['date_time', 'price']);
    $mail = $notification->toMail($ctx['participant']);
    $html = (string) $mail->render();

    foreach ([
        'notifications.subject_roster_reinvite',
        'notifications.body_roster_reinvite',
        'notifications.body_roster_reinvite_unchanged',
        'notifications.action_roster_reinvite_view',
        'notifications.preheader_roster_reinvite',
        'notifications.preheader_roster_reinvite_unchanged',
        'notifications.content_roster_reinvite_changed_heading',
    ] as $key) {
        expect(__($key, ['game' => 'x', 'inviter' => 'y', 'replaced' => 'z'], 'de'))->not->toBe($key);
    }

    expect($mail->subject)->toBe(__('notifications.subject_roster_reinvite', ['game' => 'Replacement game'], 'de'));
    expect(strip_tags($html))->toContain(rosterReinviteBody('notifications.body_roster_reinvite', [
        'inviter' => 'Host', 'game' => 'Replacement game', 'replaced' => 'Cancelled game',
    ], 'de'));
    expect($html)->toContain(e(__('notifications.content_roster_reinvite_changed_heading', [], 'de')));
    expect($html)->toContain(e(__('common.field_date_time', [], 'de')));
    expect($html)->toContain(e(__('billing.content_price_eur', [], 'de')));
    expect($mail->viewData['preheader'])->toBe(__('notifications.preheader_roster_reinvite', [], 'de'));
    expect($notification->toPush($ctx['participant'])->body)
        ->toBe(__('notifications.subject_roster_reinvite', ['game' => 'Replacement game'], 'de'));
});

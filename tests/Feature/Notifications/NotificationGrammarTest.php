<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use App\Notifications\EntityInvitation;
use App\Notifications\NewApplication;
use App\Notifications\NewFollower;
use App\Notifications\ParticipantJoined;
use App\Services\NotificationQueryService;
use Illuminate\Support\Str;

/**
 * Insert a notification row exactly as toDatabase() would write it, so the
 * read side (NotificationQueryService) can group it without triggering any
 * delivery channel.
 *
 * @param  array<string, mixed>  $data
 */
function createGrammarNotificationRow(User $recipient, string $typeClass, array $data): void
{
    $recipient->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => $typeClass,
        'data' => $data,
    ]);
}

// ══════════════════════════════════════════════════════
// Grouped display: German verb agreement with actor count
// ══════════════════════════════════════════════════════

describe('grouped display verb agreement', function () {
    it('keeps the singular German verb for a single actor', function () {
        app()->setLocale('de');
        $recipient = User::factory()->create();

        createGrammarNotificationRow($recipient, NewFollower::class, [
            'type' => 'new_follower',
            'follower_name' => 'Anna',
        ]);

        $display = app(NotificationQueryService::class)
            ->getGroupedForUser($recipient)
            ->first()
            ->displayString;

        expect($display)
            ->toContain(__('notifications.verb_new_follower', [], 'de'))
            ->not->toContain(__('notifications.verb_new_follower_plural', [], 'de'));
    });

    it('uses the plural German verb when two actors are grouped', function () {
        app()->setLocale('de');
        $recipient = User::factory()->create();

        foreach (['Anna', 'Ben'] as $name) {
            createGrammarNotificationRow($recipient, NewFollower::class, [
                'type' => 'new_follower',
                'follower_name' => $name,
            ]);
        }

        $display = app(NotificationQueryService::class)
            ->getGroupedForUser($recipient)
            ->first()
            ->displayString;

        expect($display)->toContain(__('notifications.verb_new_follower_plural', [], 'de'))
            ->and($display)->not->toContain(__('notifications.verb_new_follower', [], 'de'));
    });

    it('uses the plural German verb when five actors are grouped', function () {
        app()->setLocale('de');
        $recipient = User::factory()->create();

        foreach (['Anna', 'Ben', 'Clara', 'Dieter', 'Emil'] as $name) {
            createGrammarNotificationRow($recipient, NewFollower::class, [
                'type' => 'new_follower',
                'follower_name' => $name,
            ]);
        }

        $display = app(NotificationQueryService::class)
            ->getGroupedForUser($recipient)
            ->first()
            ->displayString;

        expect($display)->toContain(__('notifications.verb_new_follower_plural', [], 'de'));
    });

    it('resolves the plural verb through the data.type tier for unified classes', function () {
        app()->setLocale('de');
        $recipient = User::factory()->create();

        foreach (['Anna', 'Ben'] as $name) {
            createGrammarNotificationRow($recipient, EntityInvitation::class, [
                'type' => 'game_invitation',
                'inviter_name' => $name,
            ]);
        }

        $display = app(NotificationQueryService::class)
            ->getGroupedForUser($recipient)
            ->first()
            ->displayString;

        expect($display)->toContain(__('notifications.verb_game_invitation_plural', [], 'de'));
    });

    it('renders uninflected English verbs for two actors', function () {
        app()->setLocale('en');
        $recipient = User::factory()->create();

        foreach (['Anna', 'Bob'] as $name) {
            createGrammarNotificationRow($recipient, NewFollower::class, [
                'type' => 'new_follower',
                'follower_name' => $name,
            ]);
        }

        $display = app(NotificationQueryService::class)
            ->getGroupedForUser($recipient)
            ->first()
            ->displayString;

        expect($display)->toContain(__('notifications.verb_new_follower', [], 'en'));
        // English verbs stay uninflected: the plural key must carry the same value.
        expect(__('notifications.verb_new_follower', [], 'en'))
            ->toBe(__('notifications.verb_new_follower_plural', [], 'en'));
    });
});

// ══════════════════════════════════════════════════════
// Gender-safe German entity copy (:entity article agreement)
// ══════════════════════════════════════════════════════

describe('gender-safe entity copy', function () {
    it('renders a gender-safe German application mail for a campaign', function () {
        app()->setLocale('de');
        $host = User::factory()->create();
        $applicant = User::factory()->create(['name' => 'Max Mustermann']);
        $campaign = Campaign::factory()->create([
            'owner_id' => $host->id,
            'game_system_id' => GameSystem::factory()->create()->id,
            'name' => ['en' => 'Schatten über Waterdeep', 'de' => 'Schatten über Waterdeep'],
        ]);

        $mail = (new NewApplication($applicant, $campaign, 'campaign'))->toMail($host);

        expect($mail->subject)
            ->toBe(__('notifications.subject_new_application', ['applicant' => 'Max Mustermann', 'entity' => 'Schatten über Waterdeep'], 'de'))
            ->and($mail->subject)->not->toContain('deinem')
            ->and($mail->introLines[0])
            ->toContain('**Max Mustermann**')
            ->toContain('**Schatten über Waterdeep**')
            ->and($mail->introLines[0])->not->toContain('deinem');
    });

    it('renders a gender-safe German participant-joined mail for a game', function () {
        app()->setLocale('de');
        $host = User::factory()->create();
        $participant = User::factory()->create(['name' => 'Ben Muster']);
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'game_system_id' => GameSystem::factory()->create()->id,
            'name' => ['en' => 'Waterdeep Dungeon', 'de' => 'Waterdeep Dungeon'],
        ]);

        $mail = (new ParticipantJoined($participant, $game, 'game'))->toMail($host);

        expect($mail->subject)
            ->toBe(__('notifications.subject_participant_joined', ['participant' => 'Ben Muster', 'entity' => 'Waterdeep Dungeon'], 'de'))
            ->and($mail->subject)->not->toContain('deinem')
            ->and($mail->introLines[0])
            ->toContain('**Ben Muster**')
            ->toContain('**Waterdeep Dungeon**')
            ->and($mail->introLines[0])->not->toContain('deinem');
    });

    it('keeps the English application copy unchanged', function () {
        app()->setLocale('en');
        $host = User::factory()->create();
        $applicant = User::factory()->create(['name' => 'Max Mustermann']);
        $campaign = Campaign::factory()->create([
            'owner_id' => $host->id,
            'game_system_id' => GameSystem::factory()->create()->id,
            'name' => ['en' => 'Schatten über Waterdeep', 'de' => 'Schatten über Waterdeep'],
        ]);

        $mail = (new NewApplication($applicant, $campaign, 'campaign'))->toMail($host);

        expect($mail->subject)
            ->toBe(__('notifications.subject_new_application', ['applicant' => 'Max Mustermann', 'entity' => 'Schatten über Waterdeep'], 'en'))
            ->and($mail->introLines[0])
            ->toContain('**Max Mustermann**')
            ->toContain('**Schatten über Waterdeep**');
    });
});

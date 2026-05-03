<?php

namespace Tests\Unit\Notifications;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Notifications\CampaignCancelled;
use App\Notifications\CampaignInvitation;
use App\Notifications\GameCancelled;
use App\Notifications\GameInvitation;
use App\Notifications\NewFollower;
use App\Notifications\PlayerBenched;
use App\Notifications\SessionReminder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\URL;

/**
 * Verify that all toPush() methods generate valid locale-prefixed URLs
 * even when URL::defaults() has NOT been set (artisan command context).
 *
 * The existing NotificationPushPayloadTest masks this bug because its
 * Pest beforeEach sets URL::defaults(['locale' => 'en']).
 */
class PushLocaleTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Intentionally do NOT call URL::defaults() — this simulates
     * artisan command context where the bug was reported.
     *
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        // Deliberately NOT calling URL::defaults(['locale' => 'en'])
        // to reproduce the artisan command context.
        app()->setLocale('en');
    }

    // -- SessionReminder ---------------------------------------------------

    public function test_session_reminder_url_contains_locale(): void
    {
        $game = Game::factory()->create(['date_time' => now()->addHour()]);
        $notifiable = User::factory()->create();

        $payload = (new SessionReminder($game))->toPush($notifiable);

        $this->assertStringContainsString('/en/games/', $payload->url);
    }

    // -- GameInvitation ----------------------------------------------------

    public function test_game_invitation_url_contains_locale(): void
    {
        $game = Game::factory()->create();
        $inviter = User::factory()->create();
        $notifiable = User::factory()->create();

        $payload = (new GameInvitation($game, $inviter))->toPush($notifiable);

        $this->assertStringContainsString('/en/games/', $payload->url);
    }

    // -- German locale variant --------------------------------------------

    public function test_game_invitation_url_respects_german_locale(): void
    {
        app()->setLocale('de');
        $game = Game::factory()->create();
        $inviter = User::factory()->create();
        $notifiable = User::factory()->create();

        $payload = (new GameInvitation($game, $inviter))->toPush($notifiable);

        $this->assertStringContainsString('/de/games/', $payload->url);
    }

    // ── preferred_language on notifiable ────────────────

    public function test_game_invitation_to_mail_uses_notifiable_preferred_language(): void
    {
        app()->setLocale('en');
        $game = Game::factory()->create();
        $inviter = User::factory()->create();
        $notifiable = User::factory()->create(['preferred_language' => \App\Enums\ContentLanguage::De]);

        $mail = (new GameInvitation($game, $inviter))->toMail($notifiable);

        // The action URL in the mail should use the notifiable's preferred language
        $this->assertStringContainsString('/de/games/', $mail->actionUrl);
    }

    public function test_game_invitation_to_database_uses_notifiable_preferred_language(): void
    {
        app()->setLocale('en');
        $game = Game::factory()->create();
        $inviter = User::factory()->create();
        $notifiable = User::factory()->create(['preferred_language' => \App\Enums\ContentLanguage::De]);

        $data = (new GameInvitation($game, $inviter))->toDatabase($notifiable);

        $this->assertStringContainsString('/de/games/', $data['action_url']);
    }

    public function test_session_reminder_to_database_uses_notifiable_preferred_language(): void
    {
        app()->setLocale('en');
        $game = Game::factory()->create(['date_time' => now()->addHour()]);
        $notifiable = User::factory()->create(['preferred_language' => \App\Enums\ContentLanguage::De]);

        $data = (new SessionReminder($game))->toDatabase($notifiable);

        $this->assertStringContainsString('/de/games/', $data['action_url']);
    }

    // -- AttendanceReported -----------------------------------------------

    public function test_attendance_reported_push_url_contains_locale(): void
    {
        $game = Game::factory()->create();
        $reporter = User::factory()->create();
        $reported = User::factory()->create();
        $notifiable = User::factory()->create();

        $report = \App\Models\AttendanceReport::create([
            'game_id' => $game->id,
            'reporter_id' => $reporter->id,
            'reported_id' => $reported->id,
            'status' => \App\Enums\AttendanceStatus::Attended,
        ]);

        $payload = (new \App\Notifications\AttendanceReported($game, $report))->toPush($notifiable);

        $this->assertStringContainsString('/en/games/', $payload->url);
    }

    public function test_debriefing_available_push_url_contains_locale(): void
    {
        $game = Game::factory()->create();
        $notifiable = User::factory()->create();

        $payload = (new \App\Notifications\DebriefingAvailable($game))->toPush($notifiable);

        $this->assertStringContainsString('/en/games/', $payload->url);
    }

}

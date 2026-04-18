<?php

use App\Models\User;
use Illuminate\Support\Facades\URL;

/*
|--------------------------------------------------------------------------
| Locale Translation Tests
|--------------------------------------------------------------------------
|
| Verify that switching locale correctly renders translated strings on
| public pages, auth views, and Livewire views.
|
*/

beforeEach(function () {
    // Override the global beforeEach locale default so we can test both locales
    URL::defaults(['locale' => 'en']);
});

// ── English locale (default) ─────────────────────────────────────────

test('home page renders English strings by default', function () {
    $response = $this->get('/en/');

    $response->assertStatus(200);
    $response->assertSee('There\'s a seat waiting for you.');
    $response->assertSee('Sign Up');
});

test('login page renders English strings', function () {
    $response = $this->get('/en/login');

    $response->assertStatus(200);
    $response->assertSee('Sign In');
    $response->assertSee('Password');
});

test('register page renders English strings', function () {
    $response = $this->get('/en/register');

    $response->assertStatus(200);
    $response->assertSee('Create Account');
    $response->assertSee('Name');
});

test('forgot password page renders English strings', function () {
    $response = $this->get('/en/forgot-password');

    $response->assertStatus(200);
    $response->assertSee('Email Password Reset Link');
});

test('about page redirects to how-it-works with English strings', function () {
    $response = $this->get('/en/about');

    $response->assertRedirect('/en/how-it-works');

    $followUp = $this->get('/en/how-it-works');
    $followUp->assertStatus(200);
    $followUp->assertSee('How Roundup Works');
});

test('contact page renders English strings', function () {
    $response = $this->get('/en/contact');

    $response->assertStatus(200);
    $response->assertSee('Send Us a Message');
    $response->assertSee('Send Message');
});

// ── German locale ────────────────────────────────────────────────────

test('home page renders German strings when locale is de', function () {
    $response = $this->get('/de/');

    $response->assertStatus(200);
    $response->assertSee('Es wartet ein Platz auf dich.');
    $response->assertSee('Registrieren');
});

test('login page renders German strings when locale is de', function () {
    $response = $this->get('/de/login');

    $response->assertStatus(200);
    $response->assertSee('Anmelden');
    $response->assertSee('Passwort');
});

test('register page renders German strings when locale is de', function () {
    $response = $this->get('/de/register');

    $response->assertStatus(200);
    $response->assertSee('Konto erstellen');
    $response->assertSee('Name');
});

test('forgot password page renders German strings when locale is de', function () {
    $response = $this->get('/de/forgot-password');

    $response->assertStatus(200);
    $response->assertSee('Link zum Zurücksetzen senden');
});

test('about page redirects to how-it-works with German strings', function () {
    $response = $this->get('/de/about');

    $response->assertRedirect('/de/how-it-works');

    $followUp = $this->get('/de/how-it-works');
    $followUp->assertStatus(200);
    $followUp->assertSee(__('events.content_how_roundup_works'));
});

test('contact page renders German strings when locale is de', function () {
    $response = $this->get('/de/contact');

    $response->assertStatus(200);
    $response->assertSee('Sende uns eine Nachricht');
    $response->assertSee('Nachricht senden');
});

// ── Translation helper tests ─────────────────────────────────────────

test('translation helper returns German when locale is de', function () {
    app()->setLocale('de');

    expect(__('auth.content_sign_in'))->toBe('Anmelden');
    expect(__('events.content_events'))->toBe('Veranstaltungen');
    expect(__('events.content_registration'))->toBe('Anmeldung');
    expect(__('auth.content_log_out'))->toBe('Abmelden');
});

test('translation helper returns English when locale is en', function () {
    app()->setLocale('en');

    expect(__('auth.content_sign_in'))->toBe('Sign In');
    expect(__('events.content_events'))->toBe('Events');
    expect(__('events.content_registration'))->toBe('Registration');
});

test('translation helper returns key for missing translation', function () {
    app()->setLocale('de');

    expect(__('Nonexistent Translation Key'))->toBe('Nonexistent Translation Key');
});

// ── Flash message translation keys exist ─────────────────────────────

test('flash message keys have German translations', function () {
    app()->setLocale('de');

    expect(__('common.content_thank_you_for_your_message'))->toBe('Vielen Dank für deine Nachricht! Wir melden uns in Kürze bei dir.');
    expect(__('events.content_registration_is_not_currently_open_for_this_event'))->toBe('Die Anmeldung für diese Veranstaltung ist derzeit nicht geöffnet.');
    expect(__('events.content_registration_has_closed'))->toBe('Die Anmeldung ist geschlossen.');
    expect(__('events.content_this_event_is_now_full'))->toBe('Diese Veranstaltung ist ausgebucht.');
    expect(__('events.content_you_are_already_registered_for_this_event'))->toBe('Du bist bereits für diese Veranstaltung angemeldet.');
    expect(__('events.flash_you_have_been_registered_successfully'))->toBe('Du wurdest erfolgreich angemeldet!');
    expect(__('billing.content_registration_submitted_payment_instructions_will'))->toBe('Anmeldung eingereicht! Zahlungsanweisungen folgen in Kürze.');
    expect(__('events.error_this_event_does_not_support_team_registration'))->toBe('Diese Veranstaltung unterstützt keine Team-Anmeldung.');
    expect(__('teams.content_please_select_a_team'))->toBe('Bitte wähle ein Team aus.');
    expect(__('teams.content_only_the_team_captain_can_register_a_team'))->toBe('Nur der Teamkapitän kann ein Team anmelden.');
    expect(__('events.error_this_event_does_not_support'))->toBe('Diese Veranstaltung unterstützt keine Einzelanmeldung.');
});

test('validation error keys have German translations', function () {
    app()->setLocale('de');

    expect(__('events.error_this_event_does_not_support_team_registration'))->not->toBe('This event does not support team registration.');
    expect(__('events.error_this_event_does_not_support'))->not->toBe('This event does not support individual registration.');
});

// ── Authenticated page German rendering ──────────────────────────────

test('dashboard renders German strings when locale is de', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    $response = $this->actingAs($user)->get('/de/dashboard');

    $response->assertStatus(200);
    $response->assertSee('Dashboard');
});

test('profile page renders German strings when locale is de', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    $response = $this->actingAs($user)->get('/de/profile');

    $response->assertStatus(200);
    $response->assertSee('Profil'); // "Profile" in German
});

test('events listing renders German strings when locale is de', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    $response = $this->actingAs($user)->get('/de/events');

    $response->assertStatus(200);
    $response->assertSee('Veranstaltungen'); // "Events" in German
});

test('teams browsing renders German strings when locale is de', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    $response = $this->actingAs($user)->get('/de/teams');

    $response->assertStatus(200);
    $response->assertSee('Teams');
});

// ── New flash/validation message keys have German translations ───────

test('team flash message keys have German translations', function () {
    app()->setLocale('de');

    expect(__('teams.flash_team_name_created_successfully', ['name' => 'Test']))->toBe('Team "Test" erfolgreich erstellt!');
    expect(__('teams.flash_team_deleted_successfully'))->toBe('Team erfolgreich gelöscht.');
    expect(__('emails.content_invite_sent_to_email', ['email' => 'test@example.com']))->toBe('Einladung an test@example.com gesendet.');
    expect(__('teams.content_welcome_to_the_team'))->toBe('Willkommen im Team!');
    expect(__('common.flash_invite_declined'))->toBe('Einladung abgelehnt.');
    expect(__('teams.flash_member_details_updated'))->toBe('Mitgliedsdetails aktualisiert.');
    expect(__('teams.content_member_removed_from_team'))->toBe('Mitglied aus dem Team entfernt.');
    expect(__('teams.content_you_have_left_the_team'))->toBe('Du hast das Team verlassen.');
    expect(__('teams.error_cannot_demote_the_last_captain'))->toBe('Der letzte Kapitän kann nicht degradiert werden.');
    expect(__('teams.error_cannot_remove_the_last_captain_role'))->toBe('Die Kapitänsrolle des letzten Kapitäns kann nicht entfernt werden.');
});

test('event flash and validation message keys have German translations', function () {
    app()->setLocale('de');

    expect(__('events.flash_event_name_created_successfully', ['name' => 'Test']))->toBe('Veranstaltung "Test" erfolgreich erstellt!');
    expect(__('events.flash_event_published'))->toBe('Veranstaltung veröffentlicht.');
    expect(__('events.content_registration_opened'))->toBe('Anmeldung geöffnet.');
    expect(__('events.content_registration_closed_2'))->toBe('Anmeldung geschlossen.');
    expect(__('events.flash_event_cancelled'))->toBe('Veranstaltung abgesagt.');
    expect(__('events.error_cannot_change_event_status_from_from_to_to', ['from' => 'draft', 'to' => 'cancelled']))->toBe('Veranstaltungsstatus kann nicht von "draft" zu "cancelled" geändert werden.');
    expect(__('events.error_cannot_publish_event_from_status_from', ['from' => 'draft']))->toBe('Veranstaltung kann nicht aus dem Status "draft" veröffentlicht werden.');
});

test('billing flash message keys have German translations', function () {
    app()->setLocale('de');

    expect(__('billing.error_no_active_subscription_to_cancel'))->toBe('Kein aktives Abonnement zum Kündigen.');
    expect(__('billing.content_your_subscription_has_been_canceled'))->toBe('Dein Abonnement wurde gekündigt. Du behältst den Zugriff bis zum Ende deines Abrechnungszeitraums.');
    expect(__('billing.content_no_subscription_available_to_resume'))->toBe('Kein Abonnement zum Fortsetzen verfügbar.');
    expect(__('billing.content_your_subscription_has_been_resumed_welcome_back'))->toBe('Dein Abonnement wurde fortgesetzt. Willkommen zurück!');
    expect(__('billing.error_this_plan_is_not_available_for_purchase_yet'))->toBe('Dieser Plan ist noch nicht zum Kauf verfügbar.');
    expect(__('billing.error_you_already_have_an_active_subscription'))->toBe('Du hast bereits ein aktives Abonnement.');
});

test('game and campaign flash message keys have German translations', function () {
    app()->setLocale('de');

    expect(__('games.flash_game_name_created_successfully', ['name' => 'Test']))->toBe('Spiel "Test" erfolgreich erstellt!');
    expect(__('campaigns.flash_campaign_name_created_successfully', ['name' => 'Test']))->toBe('Kampagne "Test" erfolgreich erstellt!');
    expect(__('games.content_you_have_joined_the_game'))->toBe('Du bist dem Spiel beigetreten!');
    expect(__('games.content_application_submitted_the_game_owner'))->toBe('Bewerbung eingereicht! Der Spieleigentümer wird sie prüfen.');
});

test('participant management flash message keys have German translations', function () {
    app()->setLocale('de');

    expect(__('common.flash_application_approved'))->toBe('Bewerbung genehmigt.');
    expect(__('common.flash_application_rejected'))->toBe('Bewerbung abgelehnt.');
    expect(__('events.flash_participant_removed'))->toBe('Teilnehmer entfernt.');
    expect(__('common.flash_invite_cancelled'))->toBe('Einladung zurückgezogen.');
    expect(__('common.error_cannot_remove_the_entity_owner', ['entity' => 'game']))->toBe('Der game-Besitzer kann nicht entfernt werden.');
});

test('registration management flash message keys have German translations', function () {
    app()->setLocale('de');

    expect(__('events.flash_registration_approved'))->toBe('Anmeldung genehmigt.');
    expect(__('events.flash_registration_rejected'))->toBe('Anmeldung abgelehnt.');
    expect(__('billing.flash_payment_confirmed'))->toBe('Zahlung bestätigt.');
    expect(__('billing.content_payment_marked_as_refunded'))->toBe('Zahlung als erstattet markiert.');
    expect(__('events.flash_registration_cancelled'))->toBe('Anmeldung storniert.');
    expect(__('common.flash_notes_saved'))->toBe('Notizen gespeichert.');
});

test('announcement flash message keys have German translations', function () {
    app()->setLocale('de');

    expect(__('events.flash_announcement_created'))->toBe('Ankündigung erstellt.');
    expect(__('events.flash_announcement_updated'))->toBe('Ankündigung aktualisiert.');
    expect(__('events.flash_announcement_published'))->toBe('Ankündigung veröffentlicht.');
    expect(__('events.flash_announcement_unpublished'))->toBe('Ankündigung unveröffentlicht.');
    expect(__('events.flash_announcement_deleted'))->toBe('Ankündigung gelöscht.');
});

test('password and profile flash message keys have German translations', function () {
    app()->setLocale('de');

    expect(__('auth.flash_password_updated_successfully'))->toBe('Passwort erfolgreich aktualisiert.');
    expect(__('profile.content_please_type_delete_to_confirm_account_deletion'))->toBe('Bitte gib LÖSCHEN ein, um die Kontolöschung zu bestätigen.');
});

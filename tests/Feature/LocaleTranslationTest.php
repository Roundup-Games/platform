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
    $followUp->assertSee(__('How Roundup Works'));
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

    expect(__('Sign In'))->toBe('Anmelden');
    expect(__('Events'))->toBe('Veranstaltungen');
    expect(__('Registration'))->toBe('Anmeldung');
    expect(__('Log Out'))->toBe('Abmelden');
});

test('translation helper returns English when locale is en', function () {
    app()->setLocale('en');

    expect(__('Sign In'))->toBe('Sign In');
    expect(__('Events'))->toBe('Events');
    expect(__('Registration'))->toBe('Registration');
});

test('translation helper returns key for missing translation', function () {
    app()->setLocale('de');

    expect(__('Nonexistent Translation Key'))->toBe('Nonexistent Translation Key');
});

// ── Flash message translation keys exist ─────────────────────────────

test('flash message keys have German translations', function () {
    app()->setLocale('de');

    expect(__('Thank you for your message! We\'ll get back to you soon.'))->toBe('Vielen Dank für deine Nachricht! Wir melden uns in Kürze bei dir.');
    expect(__('Registration is not currently open for this event.'))->toBe('Die Anmeldung für diese Veranstaltung ist derzeit nicht geöffnet.');
    expect(__('Registration has closed.'))->toBe('Die Anmeldung ist geschlossen.');
    expect(__('This event is now full.'))->toBe('Diese Veranstaltung ist ausgebucht.');
    expect(__('You are already registered for this event.'))->toBe('Du bist bereits für diese Veranstaltung angemeldet.');
    expect(__('You have been registered successfully!'))->toBe('Du wurdest erfolgreich angemeldet!');
    expect(__('Registration submitted! Payment instructions will follow.'))->toBe('Anmeldung eingereicht! Zahlungsanweisungen folgen in Kürze.');
    expect(__('This event does not support team registration.'))->toBe('Diese Veranstaltung unterstützt keine Team-Anmeldung.');
    expect(__('Please select a team.'))->toBe('Bitte wähle ein Team aus.');
    expect(__('Only the team captain can register a team.'))->toBe('Nur der Teamkapitän kann ein Team anmelden.');
    expect(__('This event does not support individual registration.'))->toBe('Diese Veranstaltung unterstützt keine Einzelanmeldung.');
});

test('validation error keys have German translations', function () {
    app()->setLocale('de');

    expect(__('This event does not support team registration.'))->not->toBe('This event does not support team registration.');
    expect(__('This event does not support individual registration.'))->not->toBe('This event does not support individual registration.');
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

    expect(__('Team ":name" created successfully!', ['name' => 'Test']))->toBe('Team "Test" erfolgreich erstellt!');
    expect(__('Team deleted successfully.'))->toBe('Team erfolgreich gelöscht.');
    expect(__('Invite sent to :email.', ['email' => 'test@example.com']))->toBe('Einladung an test@example.com gesendet.');
    expect(__('Welcome to the team!'))->toBe('Willkommen im Team!');
    expect(__('Invite declined.'))->toBe('Einladung abgelehnt.');
    expect(__('Member details updated.'))->toBe('Mitgliedsdetails aktualisiert.');
    expect(__('Member removed from team.'))->toBe('Mitglied aus dem Team entfernt.');
    expect(__('You have left the team.'))->toBe('Du hast das Team verlassen.');
    expect(__('Cannot demote the last captain.'))->toBe('Der letzte Kapitän kann nicht degradiert werden.');
    expect(__('Cannot remove the last captain role.'))->toBe('Die Kapitänsrolle des letzten Kapitäns kann nicht entfernt werden.');
});

test('event flash and validation message keys have German translations', function () {
    app()->setLocale('de');

    expect(__('Event ":name" created successfully!', ['name' => 'Test']))->toBe('Veranstaltung "Test" erfolgreich erstellt!');
    expect(__('Event published.'))->toBe('Veranstaltung veröffentlicht.');
    expect(__('Registration opened.'))->toBe('Anmeldung geöffnet.');
    expect(__('Registration closed.'))->toBe('Anmeldung geschlossen.');
    expect(__('Event cancelled.'))->toBe('Veranstaltung abgesagt.');
    expect(__('Cannot change event status from ":from" to ":to".', ['from' => 'draft', 'to' => 'cancelled']))->toBe('Veranstaltungsstatus kann nicht von "draft" zu "cancelled" geändert werden.');
    expect(__('Cannot publish event from status ":from".', ['from' => 'draft']))->toBe('Veranstaltung kann nicht aus dem Status "draft" veröffentlicht werden.');
});

test('billing flash message keys have German translations', function () {
    app()->setLocale('de');

    expect(__('No active subscription to cancel.'))->toBe('Kein aktives Abonnement zum Kündigen.');
    expect(__('Your subscription has been canceled. You will retain access until the end of your billing period.'))->toBe('Dein Abonnement wurde gekündigt. Du behältst den Zugriff bis zum Ende deines Abrechnungszeitraums.');
    expect(__('No subscription available to resume.'))->toBe('Kein Abonnement zum Fortsetzen verfügbar.');
    expect(__('Your subscription has been resumed. Welcome back!'))->toBe('Dein Abonnement wurde fortgesetzt. Willkommen zurück!');
    expect(__('This plan is not available for purchase yet.'))->toBe('Dieser Plan ist noch nicht zum Kauf verfügbar.');
    expect(__('You already have an active subscription.'))->toBe('Du hast bereits ein aktives Abonnement.');
});

test('game and campaign flash message keys have German translations', function () {
    app()->setLocale('de');

    expect(__('Game ":name" created successfully!', ['name' => 'Test']))->toBe('Spiel "Test" erfolgreich erstellt!');
    expect(__('Campaign ":name" created successfully!', ['name' => 'Test']))->toBe('Kampagne "Test" erfolgreich erstellt!');
    expect(__('You have joined the game!'))->toBe('Du bist dem Spiel beigetreten!');
    expect(__('Application submitted! The game owner will review it.'))->toBe('Bewerbung eingereicht! Der Spieleigentümer wird sie prüfen.');
});

test('participant management flash message keys have German translations', function () {
    app()->setLocale('de');

    expect(__('Application approved.'))->toBe('Bewerbung genehmigt.');
    expect(__('Application rejected.'))->toBe('Bewerbung abgelehnt.');
    expect(__('Participant removed.'))->toBe('Teilnehmer entfernt.');
    expect(__('Invite cancelled.'))->toBe('Einladung zurückgezogen.');
    expect(__('Cannot remove the :entity owner.', ['entity' => 'game']))->toBe('Der game-Besitzer kann nicht entfernt werden.');
});

test('registration management flash message keys have German translations', function () {
    app()->setLocale('de');

    expect(__('Registration approved.'))->toBe('Anmeldung genehmigt.');
    expect(__('Registration rejected.'))->toBe('Anmeldung abgelehnt.');
    expect(__('Payment confirmed.'))->toBe('Zahlung bestätigt.');
    expect(__('Payment marked as refunded.'))->toBe('Zahlung als erstattet markiert.');
    expect(__('Registration cancelled.'))->toBe('Anmeldung storniert.');
    expect(__('Notes saved.'))->toBe('Notizen gespeichert.');
});

test('announcement flash message keys have German translations', function () {
    app()->setLocale('de');

    expect(__('Announcement created.'))->toBe('Ankündigung erstellt.');
    expect(__('Announcement updated.'))->toBe('Ankündigung aktualisiert.');
    expect(__('Announcement published.'))->toBe('Ankündigung veröffentlicht.');
    expect(__('Announcement unpublished.'))->toBe('Ankündigung unveröffentlicht.');
    expect(__('Announcement deleted.'))->toBe('Ankündigung gelöscht.');
});

test('password and profile flash message keys have German translations', function () {
    app()->setLocale('de');

    expect(__('Password updated successfully.'))->toBe('Passwort erfolgreich aktualisiert.');
    expect(__('Please type DELETE to confirm account deletion.'))->toBe('Bitte gib LÖSCHEN ein, um die Kontolöschung zu bestätigen.');
});

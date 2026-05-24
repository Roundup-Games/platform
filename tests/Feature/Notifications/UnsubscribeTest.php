<?php

use App\Enums\NotificationCategory;
use App\Models\User;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->user = User::factory()->create();
});

// smoke: signed URL unsubscribe
it('unsubscribes user from a notification category via signed URL', function () {
    $category = 'game_invitation';
    $url = URL::signedRoute('notifications.unsubscribe', [
        'locale' => 'en',
        'user' => $this->user->id,
        'category' => $category,
    ]);

    // Set initial settings with mail enabled
    $this->user->update([
        'notification_settings' => NotificationCategory::defaultSettings(),
    ]);

    $response = $this->actingAs($this->user)->get($url);

    $response->assertRedirect(route('profile.show', ['locale' => 'en']));
    $response->assertSessionHas('status');

    // Verify mail is now disabled for that category
    $settings = $this->user->fresh()->notification_settings;
    expect($settings[$category]['mail'])->toBeFalse();
    expect($settings[$category]['database'])->toBeTrue();
})->group('smoke');

it('preserves other category settings when unsubscribing', function () {
    $category = 'new_follower';
    $url = URL::signedRoute('notifications.unsubscribe', [
        'locale' => 'en',
        'user' => $this->user->id,
        'category' => $category,
    ]);

    $this->user->update([
        'notification_settings' => NotificationCategory::defaultSettings(),
    ]);

    $this->actingAs($this->user)->get($url);

    $settings = $this->user->fresh()->notification_settings;
    // new_follower mail should be off
    expect($settings['new_follower']['mail'])->toBeFalse();
    // game_invitation mail should still be on (default)
    expect($settings['game_invitation']['mail'])->toBeTrue();
});

it('rejects unsigned URLs with 403', function () {
    $url = url('/en/notifications/unsubscribe/' . $this->user->id . '/game_invitation');

    $this->actingAs($this->user)
        ->get($url)
        ->assertForbidden();
});

it('rejects tampered signatures with 403', function () {
    $url = URL::signedRoute('notifications.unsubscribe', [
        'locale' => 'en',
        'user' => $this->user->id,
        'category' => 'game_invitation',
    ]);

    // Tamper with the URL
    $tamperedUrl = str_replace('game_invitation', 'campaign_invitation', $url);

    $this->actingAs($this->user)
        ->get($tamperedUrl)
        ->assertForbidden();
});

it('returns 404 for unknown category', function () {
    $url = URL::signedRoute('notifications.unsubscribe', [
        'locale' => 'en',
        'user' => $this->user->id,
        'category' => 'nonexistent_category',
    ]);

    $this->actingAs($this->user)
        ->get($url)
        ->assertNotFound();
});

it('redirects to home for non-logged-in users', function () {
    $category = 'game_invitation';
    $url = URL::signedRoute('notifications.unsubscribe', [
        'locale' => 'en',
        'user' => $this->user->id,
        'category' => $category,
    ]);

    $this->user->update([
        'notification_settings' => NotificationCategory::defaultSettings(),
    ]);

    $response = $this->get($url);

    $response->assertRedirect(route('home', ['locale' => 'en']));
    $response->assertSessionHas('status');

    // Verify settings were still updated
    $settings = $this->user->fresh()->notification_settings;
    expect($settings[$category]['mail'])->toBeFalse();
});

it('generates valid unsubscribe URLs in notification emails', function () {
    $notification = new \App\Notifications\EntityInvitation(
        entity: \App\Models\Game::factory()->create(),
        inviter: User::factory()->create(),
    );

    $mail = $notification->toMail($this->user);
    $rendered = (string) $mail->render();

    // Should contain an unsubscribe link
    expect($rendered)->toContain('notifications/unsubscribe/' . $this->user->id . '/game_invitation');
    expect($rendered)->toContain('signature');
});

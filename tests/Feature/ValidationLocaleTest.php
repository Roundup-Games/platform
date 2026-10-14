<?php

use App\Livewire\Games\CreateGame;
use App\Models\User;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;

/*
|--------------------------------------------------------------------------
| Validation locale coverage (M066/S02/T01)
|--------------------------------------------------------------------------
|
| lang/{en,de}/validation.php and passwords.php are published from the
| framework. These tests pin the contract users actually experience:
| German form submits render German messages with friendly attribute
| names (matching the form labels), and the uuid rule — a developer
| concept that never reaches users — maps to a plain-language choice
| message for the game-system picker.
|
| Expectations are resolved through the translator (never literal copy):
| a wording change in lang/ must never break these tests.
|
*/

// ─── End-to-end: de-locale form submit ────────────────────────

it('renders German validation with friendly attribute names on the create-game form', function () {
    app()->setLocale('de');
    $user = User::factory()->create(['profile_complete' => true]);

    $errors = Livewire\Livewire::actingAs($user)
        ->test(CreateGame::class)
        ->call('selectType', 'ttrpg')
        ->set('name', '')
        ->set('date_time', 'not-a-date')
        ->set('max_players', 31)
        ->call('save')
        ->assertHasErrors(['name', 'date_time', 'max_players'])
        ->instance()
        ->getErrorBag();

    expect($errors->first('name'))
        ->toBe(__('validation.required', ['attribute' => __('validation.attributes.name', [], 'de')], 'de'))
        ->and($errors->first('date_time'))
        ->toBe(__('validation.date', ['attribute' => __('validation.attributes.date_time', [], 'de')], 'de'))
        ->and($errors->first('max_players'))
        ->toBe(__('validation.max.numeric', [
            'attribute' => __('validation.attributes.max_players', [], 'de'),
            'max' => 30,
        ], 'de'));
});

it('shows the German validation copy in the rendered form markup', function () {
    app()->setLocale('de');
    $user = User::factory()->create(['profile_complete' => true]);

    Livewire\Livewire::actingAs($user)
        ->test(CreateGame::class)
        ->call('selectType', 'ttrpg')
        ->set('max_players', 31)
        ->call('save')
        ->assertHasErrors(['max_players'])
        ->assertSeeHtml(__('validation.max.numeric', [
            'attribute' => __('validation.attributes.max_players', [], 'de'),
            'max' => 30,
        ], 'de'));
});

// ─── uuid rule: plain-language choice message ─────────────────

it('maps the game_systems uuid failure to a plain-language choice message in German', function () {
    app()->setLocale('de');

    $validator = Validator::make(
        ['game_systems' => ['keine-uuid']],
        [
            'game_systems' => ['nullable', 'array', 'max:6'],
            'game_systems.*' => ['required', 'uuid'],
        ],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('game_systems.0'))
        ->toBe(Lang::get('validation', [], 'de')['custom']['game_systems.*']['uuid'])
        // Plain-language guard: whatever the custom copy says, the rendered
        // message must not leak the "uuid" developer term back to users.
        ->and($validator->errors()->first('game_systems.0'))
        ->not->toContain('uuid')
        ->not->toContain('UUID');
});

it('overrides the stock uuid message and keeps friendly English attribute names', function () {
    app()->setLocale('en');

    $validator = Validator::make(
        ['game_system_id' => 'not-a-uuid', 'game_systems' => ['not-a-uuid']],
        [
            'game_system_id' => ['nullable', 'uuid'],
            'game_systems' => ['nullable', 'array'],
            'game_systems.*' => ['required', 'uuid'],
        ],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('game_system_id'))
        ->toBe(__('validation.uuid', ['attribute' => __('validation.attributes.game_system_id', [], 'en')], 'en'))
        ->and($validator->errors()->first('game_systems.0'))
        ->toBe(Lang::get('validation', [], 'en')['custom']['game_systems.*']['uuid']);
});

// ─── Onboarding + event fee attribute names ───────────────────

it('uses friendly German names for onboarding fields', function () {
    app()->setLocale('de');

    $validator = Validator::make(
        ['city' => '', 'slug' => 'ab'],
        [
            'city' => ['required', 'string'],
            'slug' => ['required', 'string', 'min:3', 'max:255'],
        ],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('city'))
        ->toBe(__('validation.required', ['attribute' => __('validation.attributes.city', [], 'de')], 'de'))
        ->and($validator->errors()->first('slug'))
        ->toBe(__('validation.min.string', [
            'attribute' => __('validation.attributes.slug', [], 'de'),
            'min' => 3,
        ], 'de'));
});

it('labels event fee fields friendly in German', function () {
    app()->setLocale('de');

    $validator = Validator::make(
        ['individual_registration_fee' => 'abc', 'max_participants' => 'x'],
        [
            'individual_registration_fee' => ['nullable', 'integer', 'min:0'],
            'max_participants' => ['nullable', 'integer', 'min:1'],
        ],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('individual_registration_fee'))
        ->toBe(__('validation.integer', ['attribute' => __('validation.attributes.individual_registration_fee', [], 'de')], 'de'))
        ->and($validator->errors()->first('max_participants'))
        ->toBe(__('validation.integer', ['attribute' => __('validation.attributes.max_participants', [], 'de')], 'de'));
});

// ─── Password broker lines ────────────────────────────────────

it('ships German password broker lines instead of the framework English ones', function () {
    foreach (['reset', 'sent'] as $line) {
        $de = __("passwords.{$line}", [], 'de');
        $en = __("passwords.{$line}", [], 'en');

        expect($de)->toBeString()->not->toBe('')
            ->and($de)->not->toBe($en);
    }
});

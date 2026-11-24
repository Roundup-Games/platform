<?php

use App\Enums\GameType;
use App\Livewire\Games\CreateGame;
use App\Models\GameSystem;
use App\Support\Edit\FieldTiers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

// The create form's field markup lives in resources/views/livewire/games/partials/form/
// and is shared with the detail-page edit mode (decision D315). These tests pin what that
// shared markup renders, so a section dropped or flattened during an extraction fails here
// instead of on the edit page. Labels are resolved through __() — never literals (K004).

// ── Inventory ──────────────────────────────────────────────

/**
 * Every field the create form collects, keyed by machine name: the label translation
 * keys the view renders, the attribute strings that must reach the DOM (wire:model
 * bindings, or the wire:key / wire:name of a nested picker that owns the field's
 * inputs) and — only when the field is type-conditional — the game types it renders
 * for.
 *
 * @return array<string, array{labels: list<string>, html?: list<string>, types?: list<GameType>}>
 */
function createGameFormFieldInventory(): array
{
    return [
        // ── Every game type ──────────────────────────────
        'name' => [
            'labels' => ['campaigns.field_session_name'],
            'html' => ['wire:model="name"'],
        ],
        'description' => [
            'labels' => ['common.field_description'],
            'html' => ['wire:model="description"'],
        ],
        'cover_image' => [
            'labels' => ['games.field_cover_image'],
            'html' => ['wire:model="cover_image"'],
        ],
        // The type itself is set by the type switcher, which offers all three labels.
        'game_type' => [
            'labels' => ['games.type_board_game', 'games.type_ttrpg', 'games.type_gathering'],
        ],
        'date_time' => [
            'labels' => ['common.field_date_time'],
            'html' => ['wire:model="date_time"'],
        ],
        'min_players' => [
            'labels' => ['games.field_min_players'],
            'html' => ['wire:model.live.debounce.400ms="min_players"'],
        ],
        'max_players' => [
            'labels' => ['games.field_max_players'],
            'html' => ['wire:model.live.debounce.400ms="max_players"'],
        ],
        'expected_duration' => [
            'labels' => ['common.content_duration_hours'],
            'html' => ['wire:model.live.debounce.400ms="expected_duration"'],
        ],
        'price' => [
            'labels' => ['billing.content_price_eur'],
            'html' => ['wire:model="price"'],
        ],
        'signup_cutoff_at' => [
            'labels' => ['games.label_signup_cutoff'],
            'html' => ['wire:model="signup_cutoff_at"'],
        ],
        // location, location_id and location_instructions are owned by the nested
        // venue picker (no wire:model of their own on this form).
        'location_id' => [
            'labels' => ['venues.action_choose_venue'],
        ],
        'location' => [
            'labels' => ['venues.action_choose_venue'],
        ],
        'location_instructions' => [
            'labels' => ['venues.field_location_instructions'],
        ],
        'language' => [
            'labels' => ['common.content_language'],
            'html' => ['wire:model="language"'],
        ],
        // This label key doubles as the section title, which the create view keeps —
        // the binding is what actually pins the visibility field.
        'visibility' => [
            'labels' => ['common.content_visibility'],
            'html' => ['wire:model="visibility"'],
        ],
        'vibe_flags' => [
            'labels' => ['common.content_vibe_flags'],
            'html' => ['wire:name="components.vibe-preference-picker"'],
        ],

        // ── Board game + TTRPG ───────────────────────────
        'game_system_id' => [
            'labels' => ['games.content_game_system'],
            'html' => ['wire:model.live="game_system_id"'],
            'types' => [GameType::BoardGame, GameType::Ttrpg],
        ],
        'bench_mode' => [
            'labels' => ['games.label_bench_mode'],
            'html' => ['role="switch"'],
            'types' => [GameType::BoardGame, GameType::Ttrpg],
        ],
        'min_reliability_preference' => [
            'labels' => ['games.field_attendance_tolerance'],
            'html' => ['wire:model="min_reliability_preference"'],
            'types' => [GameType::BoardGame, GameType::Ttrpg],
        ],

        // ── TTRPG only ───────────────────────────────────
        'experience_level' => [
            'labels' => ['discovery.content_experience_level'],
            'html' => ['wire:model="experience_level"'],
            'types' => [GameType::Ttrpg],
        ],
        'safety_rules' => [
            'labels' => ['safety.content_safety_tools'],
            'html' => ['wire:key="game-safety-picker-ttrpg"'],
            'types' => [GameType::Ttrpg],
        ],

        // ── Gathering only ───────────────────────────────
        'game_systems' => [
            'labels' => ['games.content_game_system'],
            'html' => ['wire:key="game-system-picker-gathering"'],
            'types' => [GameType::Gathering],
        ],
        'host_note' => [
            'labels' => ['games.field_host_note'],
            'html' => ['wire:model="host_note"'],
            'types' => [GameType::Gathering],
        ],

        // ── Board game only ──────────────────────────────
        'comfort_notes' => [
            'labels' => ['games.field_comfort_notes'],
            'html' => ['wire:model="comfort_notes"'],
            'types' => [GameType::BoardGame],
        ],
    ];
}

/**
 * Fields FieldTiers classifies that the create form has never rendered an input for —
 * they are validation rule keys only (CreateGame::rules()), so there is no label to see.
 * A field may only live here while that stays true: give it an input and it must move
 * into createGameFormFieldInventory() instead.
 *
 * @return list<string>
 */
function createGameFormRuleOnlyFields(): array
{
    return [
        'complexity',
        'minimum_requirements',
    ];
}

/**
 * @param  array{labels: list<string>, html?: list<string>, types?: list<GameType>}  $spec
 * @return list<GameType>
 */
function createGameFormFieldTypes(array $spec): array
{
    return $spec['types'] ?? GameType::cases();
}

/**
 * @param  array{labels: list<string>, html?: list<string>, types?: list<GameType>}  $spec
 * @return list<string>
 */
function createGameFormFieldAttributes(array $spec): array
{
    return $spec['html'] ?? [];
}

/**
 * @return array<string, array<string, array{labels: list<string>, html?: list<string>, types?: list<GameType>}>>
 */
function createGameFormFieldInventoryByRendering(GameType $type): array
{
    $renders = [];
    $skips = [];

    foreach (createGameFormFieldInventory() as $field => $spec) {
        if (in_array($type, createGameFormFieldTypes($spec), true)) {
            $renders[$field] = $spec;
        } else {
            $skips[$field] = $spec;
        }
    }

    return ['renders' => $renders, 'skips' => $skips];
}

/**
 * @return array<string, GameType>
 */
function createGameFormTypeDataset(): array
{
    $dataset = [];

    foreach (GameType::cases() as $type) {
        $dataset[$type->value] = $type;
    }

    return $dataset;
}

function createGameFormRenderComponent(GameType $type): Testable
{
    return Livewire::actingAs(gameTestCreateUserWithPermission('create game'))
        ->test(CreateGame::class)
        ->call('selectType', $type->value);
}

// ═══════════════════════════════════════════════════════════
// FIELD PRESENCE — every field renders, per game type
// ═══════════════════════════════════════════════════════════

describe('CreateGame — form render', function () {
    it('renders every field of the form', function (GameType $type) {
        $component = createGameFormRenderComponent($type);

        foreach (createGameFormFieldInventoryByRendering($type)['renders'] as $spec) {
            foreach ($spec['labels'] as $labelKey) {
                $component->assertSee(__($labelKey));
            }

            $component->assertSeeHtml(createGameFormFieldAttributes($spec));
        }
    })->with(createGameFormTypeDataset());

    it('keeps type-specific fields out of the other game types', function (GameType $type) {
        $component = createGameFormRenderComponent($type);
        $inventory = createGameFormFieldInventoryByRendering($type);

        // A label another field renders for this type (the single- and the
        // multi-system picker share one) cannot prove absence on its own.
        $renderedLabelKeys = [];
        foreach ($inventory['renders'] as $spec) {
            $renderedLabelKeys = [...$renderedLabelKeys, ...$spec['labels']];
        }

        foreach ($inventory['skips'] as $field => $spec) {
            foreach ($spec['labels'] as $labelKey) {
                if (in_array($labelKey, $renderedLabelKeys, true)) {
                    continue;
                }

                $component->assertDontSee(__($labelKey), false);
            }

            $component->assertDontSeeHtml(createGameFormFieldAttributes($spec));
        }
    })->with(createGameFormTypeDataset());

    it('accounts for every field FieldTiers classifies', function () {
        $classified = array_keys(FieldTiers::GAME_TIERS);
        $asserted = array_keys(createGameFormFieldInventory());

        // Direction-dependent fields (FieldTiers::DIRECTION_DEPENDENT) and the one
        // form field that is not a column of its own (board games store comfort_notes
        // inside safety_rules) are asserted here but are not fixed-tier keys.
        $formOnly = [...array_keys(FieldTiers::DIRECTION_DEPENDENT), 'comfort_notes'];

        expect(array_values(array_diff($classified, $asserted, createGameFormRuleOnlyFields())))
            ->toBe([], 'A classified game field is neither asserted on the form nor known to be rule-only.');

        expect(array_values(array_diff($asserted, $classified, $formOnly)))
            ->toBe([], 'The inventory asserts a field FieldTiers does not classify.');

        expect(array_values(array_diff(createGameFormRuleOnlyFields(), $classified)))
            ->toBe([], 'A rule-only field is not classified by FieldTiers.');
    });
});

// ═══════════════════════════════════════════════════════════
// VALIDATION FEEDBACK — every @error block stays with its field
// ═══════════════════════════════════════════════════════════

/**
 * Input that fails validation for every field that has an inline error block on the
 * given game type.
 *
 * @return array<string, mixed>
 */
function createGameFormInvalidInput(GameType $type, GameSystem $system): array
{
    $input = [
        'name' => '',
        'description' => str_repeat('x', 5001),
        'date_time' => '',
        'signup_cutoff_at' => '2030-01-01T18:00',
        'price' => 'abc',
        'min_players' => 0,
        'max_players' => 1,
        'language' => 'xx',
        'visibility' => 'nope',
    ];

    return match ($type) {
        GameType::BoardGame => $input + [
            'comfort_notes' => str_repeat('c', 1001),
            'min_reliability_preference' => '101',
        ],
        GameType::Ttrpg => $input + [
            'experience_level' => 'nope',
            'min_reliability_preference' => '101',
        ],
        GameType::Gathering => $input + [
            'host_note' => str_repeat('h', 1001),
            'game_systems' => array_fill(0, 7, $system->id),
        ],
    };
}

/**
 * @return list<string>
 */
function createGameFormInlineErrorFields(GameType $type): array
{
    $fields = [
        'name', 'description', 'cover_image', 'date_time', 'signup_cutoff_at', 'price',
        'min_players', 'max_players', 'language', 'visibility',
    ];

    return match ($type) {
        GameType::BoardGame => [...$fields, 'comfort_notes', 'min_reliability_preference'],
        GameType::Ttrpg => [...$fields, 'experience_level', 'min_reliability_preference'],
        GameType::Gathering => [...$fields, 'host_note', 'game_systems'],
    };
}

describe('CreateGame — form validation feedback', function () {
    it('shows each field validation error beside its field', function (GameType $type) {
        Storage::fake('public');
        $system = GameSystem::factory()->create();

        $component = createGameFormRenderComponent($type)
            ->set(createGameFormInvalidInput($type, $system))
            ->set('cover_image', UploadedFile::fake()->create('not-an-image.pdf', 100, 'application/pdf'))
            ->call('save');

        foreach (createGameFormInlineErrorFields($type) as $field) {
            // The validator's own message keeps this wording-agnostic: whatever the
            // field failed with must come out of that field's @error block.
            $message = $component->errors()->first($field);

            expect($message)->not->toBe('', "Field [{$field}] did not fail validation — the invalid input fixture is stale.");

            $component->assertSeeHtml('<p class="mt-1 text-sm text-error">'.e($message).'</p>');
        }
    })->with(createGameFormTypeDataset());
});

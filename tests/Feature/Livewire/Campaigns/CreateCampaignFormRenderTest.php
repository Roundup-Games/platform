<?php

use App\Enums\GameType;
use App\Livewire\Campaigns\CreateCampaign;
use App\Models\GameSystem;
use App\Models\User;
use App\Support\Edit\FieldTiers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

// The create form's field markup lives in resources/views/livewire/campaigns/partials/form/
// and is shared with the detail-page edit mode (decision D317). These tests pin what that
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
 * Mirrors FieldTiers::CAMPAIGN_TIERS: campaigns recur instead of meeting once
 * (recurrence + time_of_day, no date_time), price per session, and store their length
 * as session_duration.
 *
 * @return array<string, array{labels: list<string>, html?: list<string>, types?: list<GameType>}>
 */
function createCampaignFormFieldInventory(): array
{
    return [
        // ── Every game type ──────────────────────────────
        'name' => [
            'labels' => ['campaigns.field_campaign_name'],
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
        'recurrence' => [
            'labels' => ['campaigns.content_recurrence'],
            'html' => ['wire:model="recurrence"'],
        ],
        'time_of_day' => [
            'labels' => ['common.field_time_of_day'],
            'html' => ['wire:model="time_of_day"'],
        ],
        'session_duration' => [
            'labels' => ['campaigns.content_session_duration_hours'],
            'html' => ['wire:model="session_duration"'],
        ],
        'price_per_session' => [
            'labels' => ['campaigns.field_price_per_session_eur'],
            'html' => ['wire:model="price_per_session"'],
        ],
        'min_players' => [
            'labels' => ['games.field_min_players'],
            'html' => ['wire:model.live.debounce.400ms="min_players"'],
        ],
        'max_players' => [
            'labels' => ['games.field_max_players'],
            'html' => ['wire:model.live.debounce.400ms="max_players"'],
        ],
        // location_id and location_instructions are owned by the nested venue
        // picker (no wire:model of their own on this form).
        'location_id' => [
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
        'complexity' => [
            'labels' => ['games.content_complexity_1_5'],
            'html' => ['wire:model="complexity"'],
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
            'html' => ['wire:key="campaign-safety-picker-ttrpg"'],
            'types' => [GameType::Ttrpg],
        ],

        // ── Gathering only ───────────────────────────────
        'game_systems' => [
            'labels' => ['games.content_game_system'],
            'html' => ['wire:key="campaign-system-picker-gathering"'],
            'types' => [GameType::Gathering],
        ],
        'host_note' => [
            'labels' => ['games.field_host_note'],
            'html' => ['wire:model="host_note"'],
            'types' => [GameType::Gathering],
        ],
    ];
}

/**
 * Fields FieldTiers classifies that the create form has never rendered an input for —
 * they are validation rule keys only (CreateCampaign::rules()), so there is no label to see.
 * A field may only live here while that stays true: give it an input and it must move
 * into createCampaignFormFieldInventory() instead.
 *
 * @return list<string>
 */
function createCampaignFormRuleOnlyFields(): array
{
    return [
        'minimum_requirements',
    ];
}

/**
 * @param  array{labels: list<string>, html?: list<string>, types?: list<GameType>}  $spec
 * @return list<GameType>
 */
function createCampaignFormFieldTypes(array $spec): array
{
    return $spec['types'] ?? GameType::cases();
}

/**
 * @param  array{labels: list<string>, html?: list<string>, types?: list<GameType>}  $spec
 * @return list<string>
 */
function createCampaignFormFieldAttributes(array $spec): array
{
    return $spec['html'] ?? [];
}

/**
 * @return array{renders: array<string, array{labels: list<string>, html?: list<string>, types?: list<GameType>}>, skips: array<string, array{labels: list<string>, html?: list<string>, types?: list<GameType>}>}
 */
function createCampaignFormFieldInventoryByRendering(GameType $type): array
{
    $renders = [];
    $skips = [];

    foreach (createCampaignFormFieldInventory() as $field => $spec) {
        if (in_array($type, createCampaignFormFieldTypes($spec), true)) {
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
function createCampaignFormTypeDataset(): array
{
    $dataset = [];

    foreach (GameType::cases() as $type) {
        $dataset[$type->value] = $type;
    }

    return $dataset;
}

function createCampaignFormRenderComponent(GameType $type): Testable
{
    return Livewire::actingAs(User::factory()->create(['profile_complete' => true]))
        ->test(CreateCampaign::class)
        ->call('selectType', $type->value);
}

// ═══════════════════════════════════════════════════════════
// FIELD PRESENCE — every field renders, per game type
// ═══════════════════════════════════════════════════════════

describe('CreateCampaign — form render', function () {
    it('renders every field of the form', function (GameType $type) {
        $component = createCampaignFormRenderComponent($type);

        foreach (createCampaignFormFieldInventoryByRendering($type)['renders'] as $spec) {
            foreach ($spec['labels'] as $labelKey) {
                $component->assertSee(__($labelKey));
            }

            $component->assertSeeHtml(createCampaignFormFieldAttributes($spec));
        }
    })->with(createCampaignFormTypeDataset());

    it('keeps type-specific fields out of the other game types', function (GameType $type) {
        $component = createCampaignFormRenderComponent($type);
        $inventory = createCampaignFormFieldInventoryByRendering($type);

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

            $component->assertDontSeeHtml(createCampaignFormFieldAttributes($spec));
        }
    })->with(createCampaignFormTypeDataset());

    it('accounts for every field FieldTiers classifies', function () {
        $classified = array_keys(FieldTiers::CAMPAIGN_TIERS);
        $asserted = array_keys(createCampaignFormFieldInventory());

        // Direction-dependent fields (FieldTiers::DIRECTION_DEPENDENT) are asserted
        // here but are not fixed-tier keys.
        $formOnly = array_keys(FieldTiers::DIRECTION_DEPENDENT);

        expect(array_values(array_diff($classified, $asserted, createCampaignFormRuleOnlyFields())))
            ->toBe([], 'A classified campaign field is neither asserted on the form nor known to be rule-only.');

        expect(array_values(array_diff($asserted, $classified, $formOnly)))
            ->toBe([], 'The inventory asserts a field FieldTiers does not classify.');

        expect(array_values(array_diff(createCampaignFormRuleOnlyFields(), $classified)))
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
function createCampaignFormInvalidInput(GameType $type, GameSystem $system): array
{
    $input = [
        'name' => '',
        'description' => str_repeat('x', 10001),
        'recurrence' => 'nope',
        'time_of_day' => '25:99',
        'session_duration' => 'abc',
        'price_per_session' => 'abc',
        'max_players' => 0,
        'language' => 'xx',
        'visibility' => 'nope',
    ];

    return match ($type) {
        GameType::BoardGame => $input + ['complexity' => '0.5'],
        GameType::Ttrpg => $input + [
            'experience_level' => 'nope',
            'complexity' => '9',
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
function createCampaignFormInlineErrorFields(GameType $type): array
{
    $fields = [
        'name', 'description', 'cover_image', 'recurrence', 'time_of_day', 'session_duration',
        'price_per_session', 'max_players', 'language', 'visibility',
    ];

    return match ($type) {
        GameType::BoardGame => [...$fields, 'complexity'],
        GameType::Ttrpg => [...$fields, 'experience_level', 'complexity'],
        GameType::Gathering => [...$fields, 'host_note', 'game_systems'],
    };
}

describe('CreateCampaign — form validation feedback', function () {
    it('shows each field validation error beside its field', function (GameType $type) {
        Storage::fake('public');
        $system = GameSystem::factory()->create();

        $component = createCampaignFormRenderComponent($type)
            ->set(createCampaignFormInvalidInput($type, $system))
            ->set('cover_image', UploadedFile::fake()->create('not-an-image.pdf', 100, 'application/pdf'))
            ->call('save');

        foreach (createCampaignFormInlineErrorFields($type) as $field) {
            // The validator's own message keeps this wording-agnostic: whatever the
            // field failed with must come out of that field's @error block.
            $message = $component->errors()->first($field);

            expect($message)->not->toBe('', "Field [{$field}] did not fail validation — the invalid input fixture is stale.");

            $component->assertSeeHtml('<p class="mt-1 text-sm text-error">'.e($message).'</p>');
        }
    })->with(createCampaignFormTypeDataset());
});

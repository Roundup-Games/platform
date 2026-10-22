<?php

use App\Dto\CharterContent;
use App\Enums\SafetyTool;
use App\Models\Campaign;
use App\Models\GameSystem;
use App\Models\Location;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

// M070/S01-T02 — CharterContent DTO: schema-v1 defaults, quick-start
// prefill from a campaign, tolerant snapshot normalization, validation
// rules, section completion, and EN/DE charter.* key parity (K004: only
// key structure is asserted, never literal locale values).

/**
 * Flatten a nested locale array into dotted leaf paths
 * (e.g. 'fields.safety.tools.label').
 *
 * @return array<int, string>
 */
function flatten_charter_keys(array $array, string $prefix = ''): array
{
    $keys = [];
    foreach ($array as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        if (is_array($value)) {
            $keys = array_merge($keys, flatten_charter_keys($value, $path));
        } else {
            $keys[] = $path;
        }
    }

    return $keys;
}

// ── blank(): schema-complete defaults ────────────────────

test('blank returns a schema-complete charter with defaults', function () {
    $content = CharterContent::blank()->toArray();

    expect(array_keys($content))->toBe(CharterContent::SECTIONS)
        ->and(array_keys($content['overview']))->toBe([
            'pitch', 'system_notes', 'tone_serious_lighthearted', 'tone_gritty_heroic',
            'tone_political_dungeon', 'themes', 'expected_shape', 'table_size', 'may_add_players',
        ])
        ->and(array_keys($content['boundaries']))->toBe([
            'lines_categories', 'veils_categories', 'lines_text', 'veils_text', 'intro_note',
        ])
        ->and(array_keys($content['safety']))->toBe([
            'tools', 'custom_note', 'signal_how', 'revisit', 'open_door',
        ])
        ->and(array_keys($content['policies']))->toBe([
            'pvp', 'character_death', 'advancement', 'loot_policy', 'homebrew', 'etiquette', 'etiquette_text',
        ])
        ->and(array_keys($content['ai_tools']))->toBe([
            'ai_prep', 'ai_transcription', 'dice', 'sheets', 'photography', 'recording', 'custom_clause',
        ])
        ->and(array_keys($content['logistics']))->toBe([
            'venue_mode', 'location_id', 'venue_access_notes', 'food_drink', 'travel_notes', 'cadence',
            'session_length', 'session_length_other', 'attendance_note', 'max_missed_sessions',
            'absence_notice', 'missing_player', 'comms', 'comms_other',
        ])
        ->and(array_keys($content['character']))->toBe([
            'build_constraints', 'backstory_requested', 'backstory_guidance',
        ]);

    // Safe schema defaults; every authoring choice starts empty.
    expect($content['overview']['tone_serious_lighthearted'])->toBe(3)
        ->and($content['overview']['tone_gritty_heroic'])->toBe(3)
        ->and($content['overview']['tone_political_dungeon'])->toBe(3)
        ->and($content['overview']['table_size'])->toBe(4)
        ->and($content['overview']['pitch'])->toBe('')
        ->and($content['overview']['expected_shape'])->toBe('')
        ->and($content['safety']['tools'])->toBe([])
        ->and($content['safety']['revisit'])->toBe('each_session')
        ->and($content['safety']['open_door'])->toBeTrue()
        ->and($content['policies']['pvp'])->toBe('')
        ->and($content['ai_tools']['ai_prep'])->toBe('')
        ->and($content['logistics']['venue_mode'])->toBe('location')
        ->and($content['logistics']['location_id'])->toBeNull()
        ->and($content['logistics']['cadence'])->toBe('')
        ->and($content['logistics']['comms'])->toBe([])
        ->and($content['character']['backstory_requested'])->toBeFalse();
});

// ── quickStart(): campaign prefill + mid defaults ─────────

test('quick start prefills from the campaign and applies mid defaults', function () {
    $system = GameSystem::factory()->create(['name' => ['en' => 'Dungeons & Dragons 5E']]);
    $location = Location::factory()->create();

    $campaign = Campaign::factory()
        ->withCampaignGameSystems([(string) $system->id])
        ->create([
            'description' => ['en' => '<p>A city of <b>shadows</b> and debts.</p>'],
            'max_players' => 5,
            'location_id' => $location->id,
            'recurrence' => 'bi-weekly',
            'session_duration' => 3.0,
        ]);

    $content = CharterContent::quickStart($campaign)->toArray();

    expect($content['overview']['pitch'])->toBe('A city of shadows and debts.')
        ->and($content['overview']['system_notes'])->toBe('Dungeons & Dragons 5E')
        ->and($content['overview']['table_size'])->toBe(5)
        ->and($content['safety']['tools'])->toBe(
            array_map(static fn (SafetyTool $tool): string => $tool->value, SafetyTool::recommended())
        )
        ->and($content['logistics']['venue_mode'])->toBe('location')
        ->and($content['logistics']['location_id'])->toBe($location->id)
        ->and($content['logistics']['cadence'])->toBe('biweekly')
        ->and($content['logistics']['session_length'])->toBe('3h')
        // Mid defaults for the choice fields
        ->and($content['policies']['pvp'])->toBe('limited')
        ->and($content['policies']['character_death'])->toBe('possible')
        ->and($content['policies']['advancement'])->toBe('milestone')
        ->and($content['policies']['homebrew'])->toBe('gm_approval')
        ->and($content['ai_tools']['ai_prep'])->toBe('case_by_case')
        ->and($content['ai_tools']['ai_transcription'])->toBe('not_at_table')
        ->and($content['ai_tools']['dice'])->toBe('physical')
        ->and($content['ai_tools']['sheets'])->toBe('either')
        ->and($content['ai_tools']['photography'])->toBe('ask_every_time')
        ->and($content['ai_tools']['recording'])->toBe('none')
        ->and($content['logistics']['food_drink'])->toBe('venue_rules')
        ->and($content['logistics']['absence_notice'])->toBe('24h')
        ->and($content['logistics']['missing_player'])->toBe('skip_session')
        ->and($content['logistics']['comms'])->toBe(['platform']);
});

test('quick start maps irregular values and empty campaign facts', function () {
    $campaign = Campaign::factory()
        ->withCampaignGameSystems([])
        ->create([
            'description' => ['en' => ''],
            'max_players' => null,
            'location_id' => null,
            'recurrence' => 'custom',
            'session_duration' => 2.5,
        ]);

    $content = CharterContent::quickStart($campaign)->toArray();

    expect($content['overview']['pitch'])->toBe('')
        ->and($content['overview']['system_notes'])->toBe('')
        ->and($content['overview']['table_size'])->toBe(4)
        ->and($content['logistics']['venue_mode'])->toBe('rotate')
        ->and($content['logistics']['location_id'])->toBeNull()
        ->and($content['logistics']['cadence'])->toBe('irregular')
        ->and($content['logistics']['session_length'])->toBe('other');
});

test('quick start clamps table size to the charter range and clips long pitches', function () {
    $long = Campaign::factory()
        ->withCampaignGameSystems([])
        ->create([
            'max_players' => 15,
            'description' => ['en' => Str::repeat('a', 2100)],
        ]);
    $tiny = Campaign::factory()
        ->withCampaignGameSystems([])
        ->create(['max_players' => 1]);

    $longContent = CharterContent::quickStart($long)->toArray();
    $tinyContent = CharterContent::quickStart($tiny)->toArray();

    expect(mb_strlen($longContent['overview']['pitch']))->toBe(2000)
        ->and($longContent['overview']['table_size'])->toBe(12)
        ->and($tinyContent['overview']['table_size'])->toBe(2);
});

// ── fromArray(): tolerant normalization ───────────────────

test('fromArray defaults missing keys and drops unknown ones', function () {
    $raw = [
        'overview' => ['pitch' => '  A heist in the fog  ', 'unknown_field' => 'x'],
        'bogus_section' => ['anything' => true],
        'safety' => ['tools' => ['lines-and-veils', 'x-card', 'made-up-tool']],
        'policies' => ['pvp' => 'limited'],
    ];

    $content = CharterContent::fromArray($raw)->toArray();
    $blank = CharterContent::blank()->toArray();

    expect($content)->not->toHaveKey('bogus_section')
        ->and($content['overview'])->not->toHaveKey('unknown_field')
        // Missing sections and fields fall back to the blank schema.
        ->and($content['boundaries'])->toBe($blank['boundaries'])
        ->and($content['ai_tools'])->toBe($blank['ai_tools'])
        ->and($content['character'])->toBe($blank['character'])
        ->and($content['logistics']['comms_other'])->toBe('')
        // Known values survive (trimmed, filtered to valid tools).
        ->and($content['overview']['pitch'])->toBe('A heist in the fog')
        ->and($content['safety']['tools'])->toBe(['lines-and-veils', 'x-card'])
        ->and($content['policies']['pvp'])->toBe('limited');
});

test('fromArray coerces types and clamps numeric ranges', function () {
    $raw = CharterContent::blank()->toArray();
    $raw['overview']['tone_serious_lighthearted'] = '5';
    $raw['overview']['tone_gritty_heroic'] = '9';   // clamps to 5
    $raw['overview']['tone_political_dungeon'] = 0; // clamps to 1
    $raw['overview']['table_size'] = '7';
    $raw['overview']['may_add_players'] = 'true';
    $raw['overview']['themes'] = ['horror', 'heist', 'horror', 'not-a-theme', 3];
    $raw['safety']['open_door'] = '0';
    $raw['policies']['pvp'] = 1; // scalar outside the enum → default ''
    $raw['policies']['loot_policy'] = '  Keep it fair  ';
    $raw['logistics']['max_missed_sessions'] = '3';
    $raw['logistics']['location_id'] = 'not-a-uuid';
    $raw['character']['backstory_requested'] = 1;

    $content = CharterContent::fromArray($raw)->toArray();

    expect($content['overview']['tone_serious_lighthearted'])->toBe(5)
        ->and($content['overview']['tone_gritty_heroic'])->toBe(5)
        ->and($content['overview']['tone_political_dungeon'])->toBe(1)
        ->and($content['overview']['table_size'])->toBe(7)
        ->and($content['overview']['may_add_players'])->toBeTrue()
        ->and($content['overview']['themes'])->toBe(['horror', 'heist'])
        ->and($content['safety']['open_door'])->toBeFalse()
        ->and($content['policies']['pvp'])->toBe('')
        ->and($content['policies']['loot_policy'])->toBe('Keep it fair')
        ->and($content['logistics']['max_missed_sessions'])->toBe(3)
        ->and($content['logistics']['location_id'])->toBeNull()
        ->and($content['character']['backstory_requested'])->toBeTrue();
});

test('normalization round-trips stably', function () {
    $raw = CharterContent::blank()->toArray();
    $raw['overview']['pitch'] = 'Stability';
    $raw['safety']['tools'] = ['session-zero', 'x-card'];
    $raw['policies']['etiquette'] = ['phones_away', 'phones_away', 'respect_time'];
    $raw['logistics']['comms'] = ['platform', 'discord'];
    $raw['logistics']['max_missed_sessions'] = 2;

    $once = CharterContent::fromArray($raw)->toArray();
    $twice = CharterContent::fromArray($once)->toArray();

    expect($twice)->toBe($once)
        ->and($once['policies']['etiquette'])->toBe(['phones_away', 'respect_time'])
        ->and(CharterContent::fromArray(CharterContent::blank()->toArray())->toArray())
        ->toBe(CharterContent::blank()->toArray());
});

// ── rules() / rulesForSection() ──────────────────────────

test('rules accept a complete quick-start charter', function () {
    $content = CharterContent::quickStart(
        Campaign::factory()->create(['session_duration' => 3.0])
    )->toArray();
    $content['overview']['expected_shape'] = 'ongoing';

    expect(Validator::make($content, CharterContent::rules())->fails())->toBeFalse();
});

test('rules reject invalid values across every section', function () {
    $content = CharterContent::blank()->toArray();
    $content['overview']['pitch'] = 'Valid pitch';
    $content['overview']['themes'] = ['not-a-theme'];
    $content['overview']['expected_shape'] = 'triangular';
    $content['overview']['table_size'] = 20;
    $content['overview']['tone_serious_lighthearted'] = 9;
    $content['boundaries']['lines_text'] = Str::repeat('x', 2001);
    $content['safety']['tools'] = ['not-a-tool'];
    $content['safety']['revisit'] = 'never';
    $content['policies']['pvp'] = 'sometimes';
    $content['ai_tools']['dice'] = 'whatever';
    $content['logistics']['cadence'] = 'daily';
    $content['logistics']['session_length'] = '6h';
    $content['logistics']['comms'] = [];
    $content['character']['build_constraints'] = Str::repeat('x', 2001);

    $validator = Validator::make($content, CharterContent::rules());

    expect($validator->fails())->toBeTrue();

    $errorKeys = collect($validator->errors()->keys());
    foreach (CharterContent::SECTIONS as $section) {
        $covered = $errorKeys->contains(
            static fn (string $key): bool => str_starts_with($key, $section.'.')
        );
        expect($covered)->toBeTrue("expected a validation error under {$section}.*");
    }
});

test('rulesForSection returns the per-section subset and composes back to the full rules', function () {
    $overviewRules = CharterContent::rulesForSection('overview');

    expect(array_keys($overviewRules))->each->toStartWith('overview.')
        ->and($overviewRules)->toHaveKey('overview.themes.*');

    $subsetCount = 0;
    foreach (CharterContent::SECTIONS as $section) {
        $subsetCount += count(CharterContent::rulesForSection($section));
    }

    expect($subsetCount)->toBe(count(CharterContent::rules()));
});

test('section helpers reject unknown sections', function () {
    CharterContent::rulesForSection('agreement');
})->throws(InvalidArgumentException::class);

test('sectionComplete rejects unknown sections', function () {
    CharterContent::sectionComplete('nope', []);
})->throws(InvalidArgumentException::class);

// ── sectionComplete() ─────────────────────────────────────

test('sectionComplete marks a blank charter incomplete everywhere except character', function () {
    $blank = CharterContent::blank()->toArray();

    expect(CharterContent::sectionComplete('overview', $blank))->toBeFalse()
        ->and(CharterContent::sectionComplete('boundaries', $blank))->toBeFalse()
        ->and(CharterContent::sectionComplete('safety', $blank))->toBeFalse()
        ->and(CharterContent::sectionComplete('policies', $blank))->toBeFalse()
        ->and(CharterContent::sectionComplete('ai_tools', $blank))->toBeFalse()
        ->and(CharterContent::sectionComplete('logistics', $blank))->toBeFalse()
        ->and(CharterContent::sectionComplete('character', $blank))->toBeTrue();
});

test('sectionComplete flips as content fills in', function () {
    $content = CharterContent::quickStart(
        Campaign::factory()->create(['session_duration' => 3.0])
    )->toArray();

    // Quick start completes everything except the overview shape choice.
    expect(CharterContent::sectionComplete('overview', $content))->toBeFalse()
        ->and(CharterContent::sectionComplete('safety', $content))->toBeTrue()
        ->and(CharterContent::sectionComplete('policies', $content))->toBeTrue()
        ->and(CharterContent::sectionComplete('ai_tools', $content))->toBeTrue()
        ->and(CharterContent::sectionComplete('logistics', $content))->toBeTrue();

    $content['overview']['expected_shape'] = 'ongoing';
    expect(CharterContent::sectionComplete('overview', $content))->toBeTrue();

    // Boundaries complete through text alone.
    $content['boundaries']['veils_text'] = 'Romance fades to black.';
    expect(CharterContent::sectionComplete('boundaries', $content))->toBeTrue();

    // Safety incomplete again once the tools are emptied.
    $content['safety']['tools'] = [];
    expect(CharterContent::sectionComplete('safety', $content))->toBeFalse();

    // Other-length sessions need a description.
    $content['logistics']['session_length'] = 'other';
    $content['logistics']['session_length_other'] = '';
    expect(CharterContent::sectionComplete('logistics', $content))->toBeFalse();
    $content['logistics']['session_length_other'] = 'Five hours with a break.';
    expect(CharterContent::sectionComplete('logistics', $content))->toBeTrue();
});

test('sectionComplete logistics mirrors the publish rules field-for-field', function () {
    $content = CharterContent::blank()->toArray();

    // Everything the OLD completeness check looked at…
    $content['logistics']['venue_mode'] = 'location';
    $content['logistics']['cadence'] = 'weekly';
    $content['logistics']['session_length'] = '3h';
    $content['logistics']['comms'] = ['discord'];

    // …but publish() also requires location (venue_mode=location),
    // food_drink, absence_notice and missing_player: the stepper dot
    // must not report complete for a section publish() would reject.
    expect(CharterContent::sectionComplete('logistics', $content))->toBeFalse();

    $content['logistics']['food_drink'] = 'byo';
    expect(CharterContent::sectionComplete('logistics', $content))->toBeFalse();

    $content['logistics']['absence_notice'] = '24h';
    expect(CharterContent::sectionComplete('logistics', $content))->toBeFalse();

    $content['logistics']['missing_player'] = 'pc_as_npc';
    expect(CharterContent::sectionComplete('logistics', $content))->toBeFalse();

    $content['logistics']['location_id'] = (string) Str::uuid();
    expect(CharterContent::sectionComplete('logistics', $content))->toBeTrue();

    // A rotating venue needs no location at all.
    $content['logistics']['venue_mode'] = 'rotate';
    $content['logistics']['location_id'] = null;
    expect(CharterContent::sectionComplete('logistics', $content))->toBeTrue();
});

// ── isMaterialChange() (M070/S03) ────────────────────────

test('a change in any material section is material', function () {
    foreach (CharterContent::MATERIAL_SECTIONS as $section) {
        $base = CharterContent::blank()->toArray();
        $amended = CharterContent::blank()->toArray();

        match ($section) {
            'boundaries' => $amended['boundaries']['lines_categories'] = ['violence'],
            'safety' => $amended['safety']['tools'] = ['lines-and-veils'],
            'policies' => $amended['policies']['pvp'] = 'none',
            'ai_tools' => $amended['ai_tools']['dice'] = 'apps_fine',
        };

        expect(CharterContent::isMaterialChange($base, $amended))
            ->toBeTrue("a change in the {$section} section is material");
    }
});

test('a change on any tone axis is material', function () {
    $axes = ['tone_serious_lighthearted', 'tone_gritty_heroic', 'tone_political_dungeon'];
    expect($axes)->toBe(
        array_values(array_diff(CharterContent::MATERIAL_OVERVIEW_FIELDS, ['themes']))
    );

    foreach ($axes as $axis) {
        $base = CharterContent::blank()->toArray();
        $amended = CharterContent::blank()->toArray();
        $amended['overview'][$axis] = 5;

        expect(CharterContent::isMaterialChange($base, $amended))
            ->toBeTrue("a change on the {$axis} axis is material");
    }
});

test('theme membership changes are material, order-only shuffles are not', function () {
    $base = CharterContent::blank()->toArray();
    $base['overview']['themes'] = ['horror', 'mystery'];

    $reordered = CharterContent::blank()->toArray();
    $reordered['overview']['themes'] = ['mystery', 'horror'];

    $added = CharterContent::blank()->toArray();
    $added['overview']['themes'] = ['horror', 'mystery', 'heist'];

    $removed = CharterContent::blank()->toArray();
    $removed['overview']['themes'] = ['horror'];

    expect(CharterContent::isMaterialChange($base, $reordered))->toBeFalse()
        ->and(CharterContent::isMaterialChange($base, $added))->toBeTrue()
        ->and(CharterContent::isMaterialChange($base, $removed))->toBeTrue();
});

test('logistics, character, and minor overview edits are not material', function () {
    // Title lives outside the content array entirely — a title-only
    // amendment compares identical content, covered by the test below.
    $base = CharterContent::blank()->toArray();

    $minor = CharterContent::blank()->toArray();
    $minor['overview']['pitch'] = 'A tighter pitch.';
    $minor['overview']['system_notes'] = 'D&D 5E (2024), PHB + XGE.';
    $minor['overview']['expected_shape'] = 'ongoing';
    $minor['overview']['table_size'] = 6;
    $minor['logistics']['cadence'] = 'weekly';
    $minor['logistics']['comms'] = ['platform', 'discord'];
    $minor['character']['build_constraints'] = 'Level 1, PHB only.';

    expect(CharterContent::isMaterialChange($base, $minor))->toBeFalse();
});

test('identical content is not material, including unnormalized shapes', function () {
    $published = CharterContent::quickStart(
        Campaign::factory()->create(['session_duration' => 3.0])
    )->toArray();
    $published['overview']['expected_shape'] = 'ongoing';

    // The same logical content stored with raw shapes — stringified ints,
    // padded strings, reversed lists — must normalize back to equal.
    $raw = $published;
    $raw['overview']['tone_serious_lighthearted'] = (string) $published['overview']['tone_serious_lighthearted'];
    $raw['overview']['pitch'] = '  '.$published['overview']['pitch'].'  ';
    $raw['safety']['tools'] = array_reverse($published['safety']['tools']);

    expect(CharterContent::isMaterialChange($published, $published))->toBeFalse()
        ->and(CharterContent::isMaterialChange($published, $raw))->toBeFalse()
        ->and(CharterContent::isMaterialChange($raw, $published))->toBeFalse();
});

// ── EN/DE locale parity ───────────────────────────────────

test('charter locale files keep deep key parity between EN and DE', function () {
    $en = include lang_path('en/charter.php');
    $de = include lang_path('de/charter.php');

    expect($en)->toBeArray()->and($de)->toBeArray();

    $enKeys = flatten_charter_keys($en);
    $deKeys = flatten_charter_keys($de);

    expect($enKeys)->toBe($deKeys);

    // No leaf may be an empty string in either locale.
    foreach (['en' => $en, 'de' => $de] as $locale => $tree) {
        foreach (flatten_charter_keys($tree) as $i => $path) {
            // Re-resolve the leaf through its dotted path.
            $value = collect(explode('.', $path))->reduce(
                static fn ($carry, string $segment) => $carry[$segment] ?? null,
                $tree
            );
            expect($value)->not->toBe('', "{$locale}: empty value at charter.{$path} (index {$i})");
        }
    }
});

test('charter locale labels cover the DTO vocabularies and agreement copy', function () {
    // Flat key scheme (i18n convention): label_theme_politics,
    // label_boundary_violence, section_overview_title, confirm_safety, ...
    foreach (['en' => include lang_path('en/charter.php'), 'de' => include lang_path('de/charter.php')] as $tree) {
        foreach (CharterContent::THEMES as $theme) {
            expect($tree)->toHaveKey("label_theme_{$theme}");
        }
        foreach (CharterContent::BOUNDARY_CATEGORIES as $category) {
            expect($tree)->toHaveKey("label_boundary_{$category}");
        }
        foreach (CharterContent::ETIQUETTE as $rule) {
            expect($tree)->toHaveKey("label_etiquette_{$rule}");
        }
        foreach (CharterContent::COMMS as $channel) {
            expect($tree)->toHaveKey("label_comms_{$channel}");
        }

        // Stepper labels cover the seven stored sections plus the agreement step.
        foreach ([...CharterContent::SECTIONS, 'agreement'] as $section) {
            expect($tree)
                ->toHaveKey("section_{$section}_title")
                ->toHaveKey("section_{$section}_short")
                ->toHaveKey("section_{$section}_description");
        }

        // §8 copy S02 will reuse verbatim for the signing checkboxes.
        expect($tree)->toHaveKeys([
            'confirm_read_attestation', 'confirm_safety', 'confirm_policies',
            'confirm_ai_tools', 'confirm_logistics',
        ]);
    }
});

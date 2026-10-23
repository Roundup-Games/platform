<?php

use App\Dto\CharterContent;
use App\Models\Location;
use App\Support\CharterDiff;

// M070/S03-T01 — CharterDiff engine: field-level diff of two charter
// content snapshots. Both sides normalize through CharterContent::fromArray,
// so raw row shapes and stored snapshots diff on equal footing; rows come
// back in a fixed order (SECTIONS order, ksorted fields) and string-list
// fields diff as sets (order-only shuffles produce nothing).

// ── Identical & unnormalized inputs ──────────────────────

test('identical content produces an empty diff', function () {
    $content = CharterContent::blank()->toArray();
    $content['overview']['pitch'] = 'Same pitch.';
    $content['safety']['tools'] = ['lines-and-veils'];

    expect(CharterDiff::changes($content, $content))->toBe([]);
});

test('both sides are normalized before diffing', function () {
    $normalized = CharterContent::blank()->toArray();
    $normalized['overview']['pitch'] = 'Trimmed';
    $normalized['overview']['tone_gritty_heroic'] = 2;
    $normalized['overview']['themes'] = ['horror', 'mystery'];

    // Same logical content stored raw: padded strings, stringified ints,
    // reversed lists with junk members, unknown sections/fields.
    $raw = $normalized;
    $raw['overview']['pitch'] = '  Trimmed  ';
    $raw['overview']['tone_gritty_heroic'] = '2';
    $raw['overview']['themes'] = ['mystery', 'horror', 'not-a-theme'];
    $raw['bogus_section'] = ['x' => 1];

    expect(CharterDiff::changes($normalized, $raw))->toBe([])
        ->and(CharterDiff::changes($raw, $normalized))->toBe([]);
});

// ── Scalar changes ───────────────────────────────────────

test('changed scalar fields emit one changed row each in deterministic order', function () {
    $old = CharterContent::blank()->toArray();

    $new = CharterContent::blank()->toArray();
    $new['overview']['pitch'] = 'A new pitch.';
    $new['overview']['tone_serious_lighthearted'] = 5;
    $new['policies']['loot_policy'] = 'Split by consensus.';
    $new['policies']['pvp'] = 'open';
    $new['logistics']['max_missed_sessions'] = 3;
    $new['character']['backstory_requested'] = true;

    // Sections in SECTIONS order; fields ksorted within each section.
    expect(CharterDiff::changes($old, $new))->toBe([
        ['section' => 'overview', 'field' => 'pitch', 'type' => 'changed', 'old' => '', 'new' => 'A new pitch.'],
        ['section' => 'overview', 'field' => 'tone_serious_lighthearted', 'type' => 'changed', 'old' => 3, 'new' => 5],
        ['section' => 'policies', 'field' => 'loot_policy', 'type' => 'changed', 'old' => null, 'new' => 'Split by consensus.'],
        ['section' => 'policies', 'field' => 'pvp', 'type' => 'changed', 'old' => '', 'new' => 'open'],
        ['section' => 'logistics', 'field' => 'max_missed_sessions', 'type' => 'changed', 'old' => null, 'new' => 3],
        ['section' => 'character', 'field' => 'backstory_requested', 'type' => 'changed', 'old' => false, 'new' => true],
    ]);
});

// ── List changes ─────────────────────────────────────────

test('list membership changes emit items_added and items_removed rows', function () {
    $old = CharterContent::blank()->toArray();
    $old['overview']['themes'] = ['horror', 'mystery'];
    $old['safety']['tools'] = ['lines-and-veils', 'x-card'];
    $old['policies']['etiquette'] = ['phones_away', 'respect_time'];

    $new = CharterContent::blank()->toArray();
    $new['overview']['themes'] = ['mystery', 'heist', 'romance'];
    $new['safety']['tools'] = ['lines-and-veils', 'script-change'];
    $new['policies']['etiquette'] = ['phones_away'];

    expect(CharterDiff::changes($old, $new))->toBe([
        ['section' => 'overview', 'field' => 'themes', 'type' => 'items_added', 'old' => [], 'new' => ['heist', 'romance']],
        ['section' => 'overview', 'field' => 'themes', 'type' => 'items_removed', 'old' => ['horror'], 'new' => []],
        ['section' => 'safety', 'field' => 'tools', 'type' => 'items_added', 'old' => [], 'new' => ['script-change']],
        ['section' => 'safety', 'field' => 'tools', 'type' => 'items_removed', 'old' => ['x-card'], 'new' => []],
        ['section' => 'policies', 'field' => 'etiquette', 'type' => 'items_removed', 'old' => ['respect_time'], 'new' => []],
    ]);
});

test('order-only list shuffles produce no rows', function () {
    $old = CharterContent::blank()->toArray();
    $old['overview']['themes'] = ['horror', 'mystery', 'heist'];
    $old['logistics']['comms'] = ['platform', 'discord'];

    $new = CharterContent::blank()->toArray();
    $new['overview']['themes'] = ['heist', 'horror', 'mystery'];
    $new['logistics']['comms'] = ['discord', 'platform'];

    expect(CharterDiff::changes($old, $new))->toBe([]);
});

test('a list-to-empty change is a single items_removed row', function () {
    $old = CharterContent::blank()->toArray();
    $old['boundaries']['veils_categories'] = ['violence', 'bigotry'];

    $new = CharterContent::blank()->toArray();
    $new['boundaries']['veils_categories'] = [];

    expect(CharterDiff::changes($old, $new))->toBe([
        ['section' => 'boundaries', 'field' => 'veils_categories', 'type' => 'items_removed', 'old' => ['violence', 'bigotry'], 'new' => []],
    ]);
});

// ── added / removed fields (schema-evolution branches) ───

test('schema-stable inputs never emit added or removed rows', function () {
    $old = CharterContent::blank()->toArray();

    $new = CharterContent::blank()->toArray();
    $new['overview']['pitch'] = 'New pitch';
    $new['boundaries']['unknown_field'] = 'dropped by normalization';

    // Normalization drops the unknown field on the new side, so nothing is
    // "added" — the only row is the pitch change.
    expect(array_column(CharterDiff::changes($old, $new), 'type'))->toBe(['changed']);
});

test('fields present on only one side emit added and removed rows', function () {
    // Schema v1 normalizes both sides to identical key sets, so these
    // branches only fire under future schema evolution (a field one
    // snapshot's schema knows and the other's doesn't) — exercised through
    // the private per-section seam, the same pattern as the reflection
    // tests elsewhere in this suite.
    $method = new ReflectionMethod(CharterDiff::class, 'sectionChanges');
    $method->setAccessible(true);

    $added = $method->invoke(null, 'overview', ['pitch' => 'Old'], ['pitch' => 'Old', 'bonus_field' => 'future']);
    expect($added)->toBe([
        ['section' => 'overview', 'field' => 'bonus_field', 'type' => 'added', 'old' => null, 'new' => 'future'],
    ]);

    $removed = $method->invoke(null, 'overview', ['pitch' => 'Old', 'legacy_field' => 'past'], ['pitch' => 'Old']);
    expect($removed)->toBe([
        ['section' => 'overview', 'field' => 'legacy_field', 'type' => 'removed', 'old' => 'past', 'new' => null],
    ]);
});

// ── Display resolution (venue names) ─────────────────────

test('resolveLocationNames swaps location uuids for venue names on both sides', function () {
    $oldVenue = Location::factory()->create(['name' => 'The Gilded Tankard']);
    $newVenue = Location::factory()->create(['name' => 'Alley Door Games']);

    $old = CharterContent::blank()->toArray();
    $old['logistics']['venue_mode'] = 'location';
    $old['logistics']['location_id'] = (string) $oldVenue->id;

    $new = CharterContent::blank()->toArray();
    $new['logistics']['venue_mode'] = 'location';
    $new['logistics']['location_id'] = (string) $newVenue->id;

    $changes = CharterDiff::changes($old, $new);
    $resolved = CharterDiff::resolveLocationNames($changes);

    expect($resolved)->toBe([
        [
            'section' => 'logistics',
            'field' => 'location_id',
            'type' => 'changed',
            'old' => 'The Gilded Tankard',
            'new' => 'Alley Door Games',
        ],
    ]);

    // The pure engine stays id-free: resolution never mutated changes().
    expect($changes[0]['old'])->toBe((string) $oldVenue->id);
});

test('resolveLocationNames keeps a uuid whose venue row no longer exists', function () {
    $vanished = Location::factory()->create(['name' => 'Vanished Venue']);
    $survivor = Location::factory()->create(['name' => 'Alley Door Games']);

    $old = CharterContent::blank()->toArray();
    $old['logistics']['venue_mode'] = 'location';
    $old['logistics']['location_id'] = (string) $vanished->id;

    $new = CharterContent::blank()->toArray();
    $new['logistics']['venue_mode'] = 'location';
    $new['logistics']['location_id'] = (string) $survivor->id;

    // The old venue's row is gone before the banner renders (deleted
    // venue): the honest fallback is the uuid pointer itself, never a
    // fabricated name — the new side still resolves.
    $vanished->delete();

    $resolved = CharterDiff::resolveLocationNames(CharterDiff::changes($old, $new));
    expect($resolved)->toBe([
        [
            'section' => 'logistics',
            'field' => 'location_id',
            'type' => 'changed',
            'old' => (string) $vanished->id,
            'new' => 'Alley Door Games',
        ],
    ]);
});

test('resolveLocationNames is a no-op for diffs without location changes', function () {
    $old = CharterContent::blank()->toArray();
    $new = CharterContent::blank()->toArray();
    $new['overview']['pitch'] = 'A new pitch.';

    $changes = CharterDiff::changes($old, $new);

    // Zero Location queries expected: the early return skips the lookup.
    expect(CharterDiff::resolveLocationNames($changes))->toBe($changes);
});

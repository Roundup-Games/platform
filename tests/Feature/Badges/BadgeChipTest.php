<?php

use App\Enums\BadgeRarity;
use App\Models\BadgeDefinition;
use App\Services\Badges\BadgeRegistry;
use App\View\Components\BadgeChip;

/**
 * x-badge-chip component class (M069 S04): the single render surface every
 * badge display flows through (profile shelf, GM Directory, gallery).
 *
 * Tested at class level on purpose — no $this->blade() component-render
 * precedent exists in the codebase, so the contract pinned here is the
 * resolution logic itself: the icon override chain, the name/tier fallback
 * chains copied from BadgeAwardedNotification, and rarity null-safety.
 * One render-through-view smoke check keeps the Blade compiling; full
 * render-through-surface assertions land with the T3/T5 hosts.
 */
it('resolves the icon with the S03 metadata override beating the synced registry column', function () {
    $column = BadgeDefinition::factory()->create([
        'key' => 'column_badge',
        'name_key' => 'badges.content_badge_regulars_table_name',
        'icon' => 'calendar_month',
    ]);

    expect((new BadgeChip($column))->icon)->toBe('calendar_month');

    $overridden = BadgeDefinition::factory()->create([
        'key' => 'override_badge',
        'name_key' => 'badges.content_badge_regulars_table_name',
        'icon' => 'calendar_month',
        'metadata' => ['icon' => 'star'],
    ]);

    expect((new BadgeChip($overridden))->icon)->toBe('star');
});

it('falls back to the neutral trophy glyph when neither override nor column carries an icon', function () {
    $bare = BadgeDefinition::factory()->create([
        'key' => 'bare_badge',
        'name_key' => 'badges.content_badge_debut_name',
        'icon' => null,
    ]);

    expect((new BadgeChip($bare))->icon)->toBe('emoji_events');
});

it('resolves the name through the lang key, degrading unknown keys to the humanized registry key', function () {
    app()->setLocale('en');

    $known = BadgeDefinition::factory()->create([
        'key' => 'regulars_table',
        'name_key' => 'badges.content_badge_regulars_table_name',
    ]);

    $chip = new BadgeChip($known);

    // Wording-agnostic pins (K004): resolves in the render locale and
    // carries distinct DE copy rather than literal values.
    expect($chip->name)
        ->toBe(__('badges.content_badge_regulars_table_name', [], 'en'))
        ->not->toBe(__('badges.content_badge_regulars_table_name', [], 'de'));

    $unknown = BadgeDefinition::factory()->create([
        'key' => 'future_badge_key',
        'name_key' => 'badges.content_badge_future_badge_name',
    ]);

    // Same degradation as the award notification: humanized registry key,
    // never a raw missing-translation string.
    expect((new BadgeChip($unknown))->name)->toBe('Future badge key');
});

it('resolves tier labels with the humanized fallback and null for tierless badges', function () {
    app()->setLocale('en');

    $tiered = BadgeDefinition::factory()->create([
        'key' => 'regulars_table',
        'name_key' => 'badges.content_badge_regulars_table_name',
    ]);

    expect((new BadgeChip($tiered, tier: 'bronze'))->tierLabel)
        ->toBe(__('badges.content_badge_tier_bronze', [], 'en'))
        ->and((new BadgeChip($tiered, tier: 'platinum'))->tierLabel)->toBe('Platinum')
        ->and((new BadgeChip($tiered))->tierLabel)->toBeNull();
});

it('maps rarity null-safely from the enum-cast column', function () {
    $uncomputed = BadgeDefinition::factory()->create([
        'key' => 'uncomputed_badge',
        'name_key' => 'badges.content_badge_debut_name',
    ]);

    expect((new BadgeChip($uncomputed))->rarity)->toBeNull();

    $rare = BadgeDefinition::factory()->create([
        'key' => 'rare_badge',
        'name_key' => 'badges.content_badge_debut_name',
        'rarity_class' => BadgeRarity::Rare->value,
    ]);

    expect((new BadgeChip($rare))->rarity)->toBe(BadgeRarity::Rare);
});

it('gives every registry definition a non-null design-system glyph', function () {
    $definitions = (new BadgeRegistry)->all();

    expect($definitions)->not->toBeEmpty();

    foreach ($definitions as $definition) {
        expect($definition->icon)->not->toBeNull()->and($definition->icon)->not->toBe('');
    }
});

it('carries the chip state captions in both locales with distinct EN and DE wording', function () {
    $keys = [
        'content_badge_state_re_earn',
        'content_badge_state_coming_soon',
        'content_badge_label_featured',
    ];

    foreach ($keys as $key) {
        $en = __("badges.{$key}", [], 'en');
        $de = __("badges.{$key}", [], 'de');

        expect($en)->not->toBe("badges.{$key}")
            ->and($de)->not->toBe("badges.{$key}")
            ->and($de)->not->toBe($en);
    }
});

it('renders every chip state through the blade view without a host surface', function () {
    app()->setLocale('en');

    $definition = BadgeDefinition::factory()->create([
        'key' => 'regulars_table',
        'name_key' => 'badges.content_badge_regulars_table_name',
        'icon' => 'calendar_month',
        'rarity_class' => BadgeRarity::Uncommon->value,
    ]);

    $data = function (array $overrides = []) use ($definition): array {
        return array_merge([
            'definition' => $definition,
            'tier' => 'bronze',
            'state' => 'held',
            'featured' => true,
            'size' => 'md',
            'icon' => 'calendar_month',
            'name' => __('badges.content_badge_regulars_table_name', [], 'en'),
            'tierLabel' => __('badges.content_badge_tier_bronze', [], 'en'),
            'rarity' => BadgeRarity::Uncommon,
        ], $overrides);
    };

    $name = e(__('badges.content_badge_regulars_table_name', [], 'en'));

    foreach (['held', 'lapsed', 'dormant', 'unearned'] as $state) {
        $html = view('components.badge-chip', $data(['state' => $state]))->render();

        expect($html)
            ->toContain('calendar_month')
            ->toContain($name)
            ->toContain('aria-hidden="true"');
    }

    // Held: filled glyph + featured star marker + sr-only rarity label.
    $held = view('components.badge-chip', $data())->render();

    expect($held)
        ->toContain("font-variation-settings: 'FILL' 1")
        ->toContain('>star</span>')
        ->toContain('sr-only');

    // Lapsed: greyed with the visible re-earn caption.
    $lapsed = view('components.badge-chip', $data(['state' => 'lapsed']))->render();

    expect($lapsed)
        ->toContain(e(__('badges.content_badge_state_re_earn', [], 'en')))
        ->toContain('opacity-60')
        ->toContain('grayscale');

    // Dormant: greyed with the visible coming-soon caption.
    $dormant = view('components.badge-chip', $data(['state' => 'dormant']))->render();

    expect($dormant)
        ->toContain(e(__('badges.content_badge_state_coming_soon', [], 'en')))
        ->toContain('opacity-60');

    // Unearned: muted outline — the badge glyph stays unfilled (the
    // featured marker is disabled here because it carries its own fill).
    $unearned = view('components.badge-chip', $data([
        'state' => 'unearned',
        'featured' => false,
        'rarity' => null,
    ]))->render();

    expect($unearned)
        ->toContain('border-outline-variant')
        ->not->toContain("font-variation-settings: 'FILL' 1");
});

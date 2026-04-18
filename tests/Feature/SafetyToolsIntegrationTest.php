<?php

use App\Enums\SafetyTool;
use App\Enums\SafetyToolCategory;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use function Pest\Laravel\{actingAs, assertDatabaseHas, get};

// ═══════════════════════════════════════════════════════════
// GAME CREATION — safety_rules JSON persistence
// ═══════════════════════════════════════════════════════════

describe('Game creation — safety rules persistence', function () {
    it('persists safety_rules JSON structure with tools and text', function () {
        seedPermissions();
        $user = User::factory()->create(['profile_complete' => true, 'can_create_public_entries' => true]);
        setPermissionsTeamId(1);
        $user->givePermissionTo('create game');
        $user->unsetRelations();

        $system = GameSystem::factory()->create();

        $safetyRules = [
            'tools' => ['session-zero', 'lines-and-veils', 'x-card'],
            'lines_and_veils_text' => 'No spiders, fade-to-black for romance',
            'custom_note' => 'We take breaks every hour',
        ];

        actingAs($user);
        $game = Game::create([
            'owner_id' => $user->id,
            'game_system_id' => $system->id,
            'name' => 'Safety Tools Test Game',
            'date_time' => now()->addDays(3),
            'description' => 'A game with safety tools',
            'expected_duration' => 3,
            'price' => 0,
            'language' => 'en',
            'location' => ['type' => 'online', 'details' => 'https://example.com'],
            'status' => 'scheduled',
            'visibility' => 'public',
            'safety_rules' => $safetyRules,
        ]);

        expect($game->safety_rules)->toBeArray();
        expect($game->safety_rules['tools'])->toBe(['session-zero', 'lines-and-veils', 'x-card']);
        expect($game->safety_rules['lines_and_veils_text'])->toBe('No spiders, fade-to-black for romance');
        expect($game->safety_rules['custom_note'])->toBe('We take breaks every hour');

        // Verify persisted to DB
        assertDatabaseHas('games', ['id' => $game->id]);
        $fresh = Game::find($game->id);
        expect($fresh->safety_rules)->toBe($safetyRules);
    });

    it('persists null safety_rules when not provided', function () {
        $game = Game::factory()->create(['safety_rules' => null]);

        expect($game->safety_rules)->toBeNull();
    });

    it('persists empty tools array within safety_rules', function () {
        $game = Game::factory()->create([
            'safety_rules' => ['tools' => [], 'lines_and_veils_text' => '', 'custom_note' => ''],
        ]);

        expect($game->safety_rules['tools'])->toBe([]);
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN CREATION — safety_rules JSON persistence
// ═══════════════════════════════════════════════════════════

describe('Campaign creation — safety rules persistence', function () {
    it('persists safety_rules JSON structure with tools and text', function () {
        seedPermissions();
        $user = User::factory()->create(['profile_complete' => true, 'can_create_public_entries' => true]);
        setPermissionsTeamId(1);
        $user->givePermissionTo('create campaign');
        $user->unsetRelations();

        $system = GameSystem::factory()->create();

        $safetyRules = [
            'tools' => ['session-zero', 'lines-and-veils', 'breaks', 'stars-and-wishes'],
            'lines_and_veils_text' => 'No graphic violence',
            'custom_note' => 'We debrief after every session',
        ];

        actingAs($user);
        $campaign = Campaign::create([
            'owner_id' => $user->id,
            'game_system_id' => $system->id,
            'name' => 'Safety Tools Test Campaign',
            'description' => 'A campaign with safety tools',
            'recurrence' => 'weekly',
            'time_of_day' => '19:00',
            'session_duration' => 3,
            'price_per_session' => 0,
            'language' => 'en',
            'status' => 'active',
            'visibility' => 'public',
            'safety_rules' => $safetyRules,
        ]);

        expect($campaign->safety_rules)->toBeArray();
        expect($campaign->safety_rules['tools'])->toBe(['session-zero', 'lines-and-veils', 'breaks', 'stars-and-wishes']);
        expect($campaign->safety_rules['lines_and_veils_text'])->toBe('No graphic violence');
        expect($campaign->safety_rules['custom_note'])->toBe('We debrief after every session');

        // Verify persisted to DB
        $fresh = Campaign::find($campaign->id);
        expect($fresh->safety_rules)->toBe($safetyRules);
    });

    it('persists null safety_rules when not provided', function () {
        $campaign = Campaign::factory()->create(['safety_rules' => null]);

        expect($campaign->safety_rules)->toBeNull();
    });
});

// ═══════════════════════════════════════════════════════════
// GAME DETAIL PAGE — safety tools display
// ═══════════════════════════════════════════════════════════

describe('Game detail page — safety tools display', function () {
    it('renders safety tools section when game has safety_rules', function () {
        $owner = User::factory()->create(['profile_complete' => true]);

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'safety_rules' => [
                'tools' => ['session-zero', 'x-card', 'breaks'],
                'lines_and_veils_text' => '',
                'custom_note' => 'Check in regularly',
            ],
        ]);

        Livewire\Livewire::test(App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertOk()
            ->assertSee('Safety Tools')
            ->assertSee('Session Zero')
            ->assertSee('X-Card')
            ->assertSee('Breaks')
            ->assertSee('Check in regularly');
    });

    it('shows Lines & Veils text when present', function () {
        $owner = User::factory()->create(['profile_complete' => true]);

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'safety_rules' => [
                'tools' => ['lines-and-veils'],
                'lines_and_veils_text' => 'No spiders, no graphic torture',
                'custom_note' => '',
            ],
        ]);

        Livewire\Livewire::test(App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertOk()
            ->assertSee('Lines & Veils Details')
            ->assertSee('No spiders, no graphic torture');
    });

    it('shows tools grouped by category with category labels', function () {
        $owner = User::factory()->create(['profile_complete' => true]);

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'safety_rules' => [
                'tools' => ['session-zero', 'x-card', 'stars-and-wishes'],
                'lines_and_veils_text' => '',
                'custom_note' => '',
            ],
        ]);

        Livewire\Livewire::test(App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertOk()
            ->assertSee('Before the Game')
            ->assertSee('During the Game')
            ->assertSee('After the Game');
    });

    it('does not render safety tools section when safety_rules is null', function () {
        $owner = User::factory()->create(['profile_complete' => true]);

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'safety_rules' => null,
        ]);

        Livewire\Livewire::test(App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertOk()
            ->assertDontSee('Lines & Veils Details')
            ->assertDontSee('Custom Safety Note');
    });

    it('includes link to safety-tools page', function () {
        $owner = User::factory()->create(['profile_complete' => true]);

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'safety_rules' => [
                'tools' => ['x-card'],
                'lines_and_veils_text' => '',
                'custom_note' => '',
            ],
        ]);

        Livewire\Livewire::test(App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertOk()
            ->assertSee('Learn more about safety tools');
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN DETAIL PAGE — safety tools display
// ═══════════════════════════════════════════════════════════

describe('Campaign detail page — safety tools display', function () {
    it('renders safety tools section when campaign has safety_rules', function () {
        $owner = User::factory()->create(['profile_complete' => true]);

        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'safety_rules' => [
                'tools' => ['session-zero', 'lines-and-veils', 'debriefing'],
                'lines_and_veils_text' => 'Fade-to-black for romance',
                'custom_note' => 'Open door policy',
            ],
        ]);

        Livewire\Livewire::test(App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertOk()
            ->assertSee('Safety Tools')
            ->assertSee('Session Zero')
            ->assertSee('Debriefing')
            ->assertSee('Fade-to-black for romance')
            ->assertSee('Open door policy');
    });

    it('does not render safety tools section when safety_rules is null', function () {
        $owner = User::factory()->create(['profile_complete' => true]);

        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'safety_rules' => null,
        ]);

        Livewire\Livewire::test(App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertOk()
            ->assertDontSee('Lines & Veils Details')
            ->assertDontSee('Custom Safety Note');
    });
});

// ═══════════════════════════════════════════════════════════
// DISCOVERY PAGE — safety tools filter
// ═══════════════════════════════════════════════════════════

describe('Discovery page — safety tools filter', function () {
    it('filters games by safety tool', function () {
        Game::factory()->create([
            'name' => 'Safe Game Session',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'safety_rules' => [
                'tools' => ['session-zero', 'x-card'],
                'lines_and_veils_text' => '',
                'custom_note' => '',
            ],
        ]);

        Game::factory()->create([
            'name' => 'Unsafe Game Session',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'safety_rules' => null,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->call('toggleSafetyTool', 'x-card')
            ->assertSee('Safe Game Session')
            ->assertDontSee('Unsafe Game Session');
    });

    it('filters campaigns by safety tool', function () {
        Campaign::factory()->create([
            'name' => 'Safe Campaign',
            'visibility' => 'public',
            'status' => 'active',
            'safety_rules' => [
                'tools' => ['lines-and-veils', 'breaks'],
                'lines_and_veils_text' => 'No spiders',
                'custom_note' => '',
            ],
        ]);

        Campaign::factory()->create([
            'name' => 'Unsafe Campaign',
            'visibility' => 'public',
            'status' => 'active',
            'safety_rules' => null,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->set('mode', 'campaigns')
            ->call('toggleSafetyTool', 'breaks')
            ->assertSee('Safe Campaign')
            ->assertDontSee('Unsafe Campaign');
    });

    it('requires all selected safety tools to match (AND logic)', function () {
        Game::factory()->create([
            'name' => 'Partial Match Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'safety_rules' => [
                'tools' => ['session-zero'],
                'lines_and_veils_text' => '',
                'custom_note' => '',
            ],
        ]);

        Game::factory()->create([
            'name' => 'Full Match Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'safety_rules' => [
                'tools' => ['session-zero', 'x-card'],
                'lines_and_veils_text' => '',
                'custom_note' => '',
            ],
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->call('toggleSafetyTool', 'session-zero')
            ->call('toggleSafetyTool', 'x-card')
            ->assertSee('Full Match Game')
            ->assertDontSee('Partial Match Game');
    });

    it('toggling a safety tool off removes it from filter', function () {
        Game::factory()->create([
            'name' => 'Tool Match Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'safety_rules' => [
                'tools' => ['session-zero'],
                'lines_and_veils_text' => '',
                'custom_note' => '',
            ],
        ]);

        Game::factory()->create([
            'name' => 'No Tools Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'safety_rules' => null,
        ]);

        // Toggle on, verify filtered
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->call('toggleSafetyTool', 'session-zero')
            ->assertSee('Tool Match Game')
            ->assertDontSee('No Tools Game');

        // Toggle off, verify both visible again
        $component
            ->call('toggleSafetyTool', 'session-zero')
            ->assertSee('Tool Match Game')
            ->assertSee('No Tools Game');
    });

    it('clearFilters resets safety_tools filter', function () {
        Game::factory()->create([
            'name' => 'Filtered Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'safety_rules' => [
                'tools' => ['session-zero'],
                'lines_and_veils_text' => '',
                'custom_note' => '',
            ],
        ]);

        Game::factory()->create([
            'name' => 'Unfiltered Game',
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'safety_rules' => null,
        ]);

        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->call('toggleSafetyTool', 'session-zero')
            ->call('clearFilters')
            ->assertSet('safety_tools', [])
            ->assertSee('Filtered Game')
            ->assertSee('Unfiltered Game');
    });

    it('hasActiveFilters returns true when safety_tools is set', function () {
        $component = Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class);
        expect($component->instance()->hasActiveFilters())->toBeFalse();

        $component->call('toggleSafetyTool', 'x-card');
        expect($component->instance()->hasActiveFilters())->toBeTrue();
    });

    it('receives safetyToolGroups in render data', function () {
        Livewire\Livewire::test(App\Livewire\Discovery\DiscoveryPage::class)
            ->assertSet('safety_tools', [])
            ->assertViewHas('safetyToolGroups');

        // Verify the grouped structure matches SafetyTool::grouped()
        $expected = SafetyTool::grouped();
        expect($expected)->toHaveKeys(['before', 'during', 'after']);
        expect($expected['before']['options'])->toHaveCount(3);
        expect($expected['during']['options'])->toHaveCount(4);
        expect($expected['after']['options'])->toHaveCount(2);
    });
});

// ═══════════════════════════════════════════════════════════
// STATIC PAGE — /safety-tools
// ═══════════════════════════════════════════════════════════

describe('/safety-tools static page', function () {
    it('returns 200 status', function () {
        get(route('safety-tools'))
            ->assertOk();
    });

    it('shows the page title and description', function () {
        get(route('safety-tools'))
            ->assertOk()
            ->assertSee('Safety Tools for Tabletop Gaming')
            ->assertSee('Everyone deserves to feel safe and comfortable at the table');
    });

    it('shows all 9 safety tool names', function () {
        $response = get(route('safety-tools'));
        $response->assertOk();

        foreach (SafetyTool::cases() as $tool) {
            $response->assertSee($tool->label());
        }
    });

    it('shows category groupings', function () {
        get(route('safety-tools'))
            ->assertOk()
            ->assertSee('Before the Game')
            ->assertSee('During the Game')
            ->assertSee('After the Game');
    });

    it('shows full descriptions for all tools (not short descriptions)', function () {
        $response = get(route('safety-tools'));
        $response->assertOk();

        foreach (SafetyTool::cases() as $tool) {
            $response->assertSee($tool->fullDescription());
        }
    });

    it('shows attribution for X-Card and Script Change', function () {
        get(route('safety-tools'))
            ->assertOk()
            ->assertSee('John Stavropoulos')
            ->assertSee('Beau Jágr Sheldon');
    });

    it('passes tools and categories to view', function () {
        $response = get(route('safety-tools'));
        $response->assertOk();

        $tools = $response->viewData('tools');
        $categories = $response->viewData('categories');

        expect($tools)->toHaveCount(9);
        expect($categories)->toHaveCount(3);
    });
});

// ═══════════════════════════════════════════════════════════
// GERMAN TRANSLATIONS — safety tool keys
// ═══════════════════════════════════════════════════════════

describe('German translations for safety tools', function () {
    it('has German translations for all safety tool UI strings', function () {
        $requiredKeys = [
            'safety.content_safety_tools',
            'safety.action_select_the_safety_tools_you',
            'safety.action_select_the_safety_tools_you_2',
            'safety.content_lines_veils_details',
            'safety.content_custom_safety_note',
            'safety.content_learn_more_about_safety_tools',
            'safety.field_safety_tools_for_tabletop_gaming',
        ];

        app()->setLocale('de');
        foreach ($requiredKeys as $key) {
            $deValue = __($key);
            expect($deValue)->not->toBe($key, "Missing de translation for: {$key}");
            expect($deValue)->not->toBeEmpty("German translation empty for key: {$key}");
        }
    });

    it('has German translations for category section descriptions', function () {
        // Category section descriptions rendered via __() in the template
        $sectionDescriptions = [
            'common.action_set_expectations_before_the_dice_start_rolling',
            'common.content_tools_you_can_use_right_at_the_table',
            'campaigns.content_reflect_and_improve_after_each_session',
        ];

        app()->setLocale('de');
        foreach ($sectionDescriptions as $key) {
            $deValue = __($key);
            expect($deValue)->not->toBe($key, "Missing de translation for: {$key}");
            expect($deValue)->not->toBeEmpty();
        }
    });

    it('SafetyToolCategory labels are hardcoded in enum (not translation-dependent)', function () {
        // Category labels come from SafetyToolCategory::label() directly,
        // not from __(). Verify the enum provides them.
        foreach (SafetyToolCategory::cases() as $category) {
            expect($category->label())->not->toBeEmpty();
        }
    });

    it('German translations are not identical to English originals', function () {
        $keysToVerify = [
            'safety.content_safety_tools',
            'safety.content_custom_safety_note',
            'safety.content_learn_more_about_safety_tools',
        ];

        app()->setLocale('en');
        $enValues = [];
        foreach ($keysToVerify as $key) {
            $enValues[$key] = __($key);
        }

        app()->setLocale('de');
        foreach ($keysToVerify as $key) {
            $deValue = __($key);
            if ($deValue !== $key) {
                expect($deValue)->not->toBe($enValues[$key], "German translation for '{$key}' should not be identical to English");
            }
        }
    });
});

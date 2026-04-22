<?php

namespace Tests\Unit;

use App\Enums\VibeFlag;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class VibeFlagExpansionTest extends TestCase
{
    // ── Enum count ────────────────────────────────────

    public function test_values_returns_30_flags(): void
    {
        $values = VibeFlag::values();

        $this->assertCount(30, $values, 'Expected 30 VibeFlag values (20 original + 10 TTRPG expansion)');
    }

    // ── Grouped structure ─────────────────────────────

    public function test_grouped_has_five_groups(): void
    {
        $grouped = VibeFlag::grouped();

        $this->assertCount(5, $grouped);
        $this->assertArrayHasKey('tone', $grouped);
        $this->assertArrayHasKey('content', $grouped);
        $this->assertArrayHasKey('playstyle', $grouped);
        $this->assertArrayHasKey('social', $grouped);
        $this->assertArrayHasKey('format', $grouped);
    }

    public function test_format_group_contains_exactly_three_flags(): void
    {
        $grouped = VibeFlag::grouped();

        $this->assertArrayHasKey('format', $grouped);
        $formatOptions = array_keys($grouped['format']['options']);

        sort($formatOptions);
        $this->assertEquals(
            ['organized-play', 'play-by-post', 'west-marches'],
            $formatOptions,
        );
    }

    public function test_playstyle_group_contains_14_flags(): void
    {
        $grouped = VibeFlag::grouped();

        $this->assertArrayHasKey('playstyle', $grouped);
        $playstyleOptions = array_keys($grouped['playstyle']['options']);

        // 7 original + 7 TTRPG playstyle = 14
        $this->assertCount(14, $playstyleOptions);

        // Verify the 7 new TTRPG playstyle flags are present
        $newPlaystyle = ['sandbox', 'rule-of-cool', 'dungeon-crawl', 'kingdom-building',
            'theater-of-the-mind', 'rules-as-written', 'roleplay-light'];
        foreach ($newPlaystyle as $flag) {
            $this->assertContains($flag, $playstyleOptions, "Expected '{$flag}' in playstyle group");
        }
    }

    // ── Mutual exclusion pairs ────────────────────────

    public function test_mutually_exclusive_pairs_count_is_8(): void
    {
        $pairs = VibeFlag::mutuallyExclusivePairs();

        $this->assertCount(8, $pairs, 'Expected 8 mutually exclusive pairs (6 original + 2 new)');
    }

    public function test_rule_of_cool_and_rules_heavy_are_paired(): void
    {
        $pairs = VibeFlag::mutuallyExclusivePairs();
        $found = false;

        foreach ($pairs as $pair) {
            $values = [$pair[0]->value, $pair[1]->value];
            if (in_array('rule-of-cool', $values) && in_array('rules-heavy', $values)) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Expected RuleOfCool ↔ RulesHeavy pair');
    }

    public function test_roleplay_light_and_roleplay_heavy_are_paired(): void
    {
        $pairs = VibeFlag::mutuallyExclusivePairs();
        $found = false;

        foreach ($pairs as $pair) {
            $values = [$pair[0]->value, $pair[1]->value];
            if (in_array('roleplay-light', $values) && in_array('roleplay-heavy', $values)) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Expected RoleplayLight ↔ RoleplayHeavy pair');
    }

    // ── Labels and translations ───────────────────────

    public function test_all_new_flags_return_non_empty_labels(): void
    {
        $newFlags = [
            VibeFlag::Sandbox,
            VibeFlag::RuleOfCool,
            VibeFlag::DungeonCrawl,
            VibeFlag::KingdomBuilding,
            VibeFlag::TheaterOfTheMind,
            VibeFlag::RulesAsWritten,
            VibeFlag::RoleplayLight,
            VibeFlag::PlayByPost,
            VibeFlag::OrganizedPlay,
            VibeFlag::WestMarches,
        ];

        foreach ($newFlags as $flag) {
            $label = $flag->label();
            $this->assertNotEmpty($label, "Label for {$flag->value} should not be empty");
            $this->assertNotEquals($flag->name, $label, "Label for {$flag->value} should be translated, not the enum name");
        }
    }

    public function test_all_new_flags_translatable_in_english(): void
    {
        App::setLocale('en');

        $expected = [
            'sandbox' => 'Sandbox / Open World',
            'rule-of-cool' => 'Rule of Cool',
            'dungeon-crawl' => 'Dungeon Crawl',
            'kingdom-building' => 'Kingdom Building',
            'theater-of-the-mind' => 'Theater of the Mind',
            'rules-as-written' => 'Rules as Written',
            'roleplay-light' => 'Roleplay-Light',
            'play-by-post' => 'Play-by-Post',
            'organized-play' => 'Organized Play',
            'west-marches' => 'West Marches',
        ];

        foreach ($expected as $value => $expectedLabel) {
            $flag = VibeFlag::from($value);
            $this->assertEquals($expectedLabel, $flag->label(), "EN label for {$value}");
        }
    }

    public function test_all_new_flags_translatable_in_german(): void
    {
        App::setLocale('de');

        $expected = [
            'sandbox' => 'Sandbox / Open World',
            'rule-of-cool' => 'Rule of Cool',
            'dungeon-crawl' => 'Dungeon-Crawl',
            'kingdom-building' => 'Königreich-Aufbau',
            'theater-of-the-mind' => 'Theater des Geistes',
            'rules-as-written' => 'Regeln wie geschrieben',
            'roleplay-light' => 'Leichtes Rollenspiel',
            'play-by-post' => 'Play-by-Post',
            'organized-play' => 'Organisiertes Spiel',
            'west-marches' => 'West Marches',
        ];

        foreach ($expected as $value => $expectedLabel) {
            $flag = VibeFlag::from($value);
            $this->assertEquals($expectedLabel, $flag->label(), "DE label for {$value}");
        }

        // Reset to default
        App::setLocale('en');
    }

    // ── All flags accounted for in groups ─────────────

    public function test_every_flag_appear_in_exactly_one_group(): void
    {
        $grouped = VibeFlag::grouped();
        $seen = [];

        foreach ($grouped as $groupKey => $group) {
            foreach (array_keys($group['options']) as $flagValue) {
                $this->assertNotContains(
                    $flagValue, $seen,
                    "Flag {$flagValue} appears in multiple groups",
                );
                $seen[] = $flagValue;
            }
        }

        // Every enum case should be in a group
        $this->assertCount(count(VibeFlag::cases()), $seen, 'Not all flags appear in grouped() output');
    }

    // ── All group labels are translatable ─────────────

    public function test_all_group_labels_are_non_empty(): void
    {
        $grouped = VibeFlag::grouped();

        foreach ($grouped as $groupKey => $group) {
            $this->assertNotEmpty($group['label'], "Group '{$groupKey}' label should not be empty");
        }
    }
}

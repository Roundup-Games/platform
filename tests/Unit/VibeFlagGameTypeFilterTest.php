<?php

namespace Tests\Unit;

use App\Enums\VibeFlag;
use Tests\TestCase;
use Tests\Traits\SetsUpLocale;

class VibeFlagGameTypeFilterTest extends TestCase
{
    use SetsUpLocale;
    // ── forGameType() ─────────────────────────────────

    public function test_board_game_returns_subset_of_flags(): void
    {
        $flags = VibeFlag::forGameType('board_game');

        $this->assertCount(15, $flags, 'board_game should return 15 flags');
    }

    public function test_board_game_includes_tone_flags(): void
    {
        $values = array_map(fn (VibeFlag $f) => $f->value, VibeFlag::forGameType('board_game'));

        $expected = ['atmospheric', 'lighthearted', 'serious', 'horror', 'humorous'];
        foreach ($expected as $v) {
            $this->assertContains($v, $values, "board_game flags should include {$v}");
        }
    }

    public function test_board_game_includes_content_flags(): void
    {
        $values = array_map(fn (VibeFlag $f) => $f->value, VibeFlag::forGameType('board_game'));

        $expected = ['family-friendly', 'mature-themes'];
        foreach ($expected as $v) {
            $this->assertContains($v, $values, "board_game flags should include {$v}");
        }
    }

    public function test_board_game_includes_playstyle_flags(): void
    {
        $values = array_map(fn (VibeFlag $f) => $f->value, VibeFlag::forGameType('board_game'));

        $expected = ['rules-light', 'rules-heavy', 'tactical', 'puzzle-solving'];
        foreach ($expected as $v) {
            $this->assertContains($v, $values, "board_game flags should include {$v}");
        }
    }

    public function test_board_game_includes_social_flags(): void
    {
        $values = array_map(fn (VibeFlag $f) => $f->value, VibeFlag::forGameType('board_game'));

        $expected = ['competitive', 'cooperative', 'new-player-friendly', 'drop-in-friendly'];
        foreach ($expected as $v) {
            $this->assertContains($v, $values, "board_game flags should include {$v}");
        }
    }

    public function test_board_game_excludes_ttrpg_only_flags(): void
    {
        $values = array_map(fn (VibeFlag $f) => $f->value, VibeFlag::forGameType('board_game'));

        $excluded = ['character-driven', 'story-rich', 'combat-focused', 'roleplay-heavy',
            'exploration', 'sandbox', 'rule-of-cool', 'dungeon-crawl', 'kingdom-building',
            'theater-of-the-mind', 'rules-as-written', 'roleplay-light',
            'play-by-post', 'organized-play', 'west-marches'];
        foreach ($excluded as $v) {
            $this->assertNotContains($v, $values, "board_game flags should NOT include {$v}");
        }
    }

    public function test_ttrpg_returns_all_flags(): void
    {
        $flags = VibeFlag::forGameType('ttrpg');

        $this->assertCount(30, $flags, 'ttrpg should return all 30 flags');
        $this->assertEquals(VibeFlag::cases(), $flags);
    }

    public function test_unknown_type_returns_all_flags(): void
    {
        $flags = VibeFlag::forGameType('unknown_type');

        $this->assertCount(30, $flags, 'Unknown type should fall back to all flags');
        $this->assertEquals(VibeFlag::cases(), $flags);
    }

    public function test_empty_string_returns_all_flags(): void
    {
        $flags = VibeFlag::forGameType('');

        $this->assertCount(30, $flags);
        $this->assertEquals(VibeFlag::cases(), $flags);
    }

    // ── groupedForGameType() ──────────────────────────

    public function test_grouped_board_game_has_four_groups(): void
    {
        $grouped = VibeFlag::groupedForGameType('board_game');

        $this->assertCount(4, $grouped);
        $this->assertArrayHasKey('tone', $grouped);
        $this->assertArrayHasKey('content', $grouped);
        $this->assertArrayHasKey('playstyle', $grouped);
        $this->assertArrayHasKey('social', $grouped);
    }

    public function test_grouped_board_game_omits_format_group(): void
    {
        $grouped = VibeFlag::groupedForGameType('board_game');

        $this->assertArrayNotHasKey('format', $grouped, 'board_game should not have a format group');
    }

    public function test_grouped_board_game_tone_has_five_options(): void
    {
        $grouped = VibeFlag::groupedForGameType('board_game');

        $this->assertCount(5, $grouped['tone']['options']);
    }

    public function test_grouped_board_game_content_has_two_options(): void
    {
        $grouped = VibeFlag::groupedForGameType('board_game');

        $this->assertCount(2, $grouped['content']['options']);
    }

    public function test_grouped_board_game_playstyle_has_four_options(): void
    {
        $grouped = VibeFlag::groupedForGameType('board_game');

        $this->assertCount(4, $grouped['playstyle']['options']);
    }

    public function test_grouped_board_game_social_has_four_options(): void
    {
        $grouped = VibeFlag::groupedForGameType('board_game');

        $this->assertCount(4, $grouped['social']['options']);
    }

    public function test_grouped_board_game_total_flags_match_for_game_type(): void
    {
        $grouped = VibeFlag::groupedForGameType('board_game');
        $totalOptions = 0;
        foreach ($grouped as $group) {
            $totalOptions += count($group['options']);
        }

        $this->assertCount(
            $totalOptions,
            VibeFlag::forGameType('board_game'),
            'Total grouped options should match forGameType count',
        );
    }

    public function test_grouped_ttrpg_has_five_groups(): void
    {
        $grouped = VibeFlag::groupedForGameType('ttrpg');

        $this->assertCount(5, $grouped);
        $this->assertEquals(VibeFlag::grouped(), $grouped);
    }

    public function test_grouped_unknown_type_returns_all_groups(): void
    {
        $grouped = VibeFlag::groupedForGameType('anything');

        $this->assertCount(5, $grouped);
        $this->assertEquals(VibeFlag::grouped(), $grouped);
    }

    public function test_grouped_for_game_type_preserves_group_labels(): void
    {
        $grouped = VibeFlag::groupedForGameType('board_game');
        $allGrouped = VibeFlag::grouped();

        foreach ($grouped as $key => $group) {
            $this->assertEquals($allGrouped[$key]['label'], $group['label'], "Group '{$key}' label should match grouped()");
        }
    }
}

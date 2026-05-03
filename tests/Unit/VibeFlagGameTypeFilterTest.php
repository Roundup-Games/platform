<?php

namespace Tests\Unit;

use App\Enums\VibeFlag;
use Tests\TestCase;
use Tests\Traits\SetsUpLocale;

class VibeFlagGameTypeFilterTest extends TestCase
{
    use SetsUpLocale;

    // ── Cross-validation: grouped count matches flat count ─────

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

    // ── Board game excludes TTRPG-only flags ──────────────────

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

    // ── Fallback behavior ─────────────────────────────────────

    public function test_ttrpg_returns_all_flags(): void
    {
        $this->assertEquals(VibeFlag::cases(), VibeFlag::forGameType('ttrpg'));
    }

    public function test_unknown_type_returns_all_flags(): void
    {
        $this->assertEquals(VibeFlag::cases(), VibeFlag::forGameType('unknown_type'));
    }

    // ── Group label preservation ──────────────────────────────

    public function test_grouped_for_game_type_preserves_group_labels(): void
    {
        $grouped = VibeFlag::groupedForGameType('board_game');
        $allGrouped = VibeFlag::grouped();

        foreach ($grouped as $key => $group) {
            $this->assertEquals($allGrouped[$key]['label'], $group['label'], "Group '{$key}' label should match grouped()");
        }
    }
}

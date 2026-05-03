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

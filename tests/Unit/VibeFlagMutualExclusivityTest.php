<?php

namespace Tests\Unit;

use App\Enums\VibeFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VibeFlagMutualExclusivityTest extends TestCase
{
    // ── Pair structure validation ─────────────────────

    public function test_returns_six_mutually_exclusive_pairs(): void
    {
        $pairs = VibeFlag::mutuallyExclusivePairs();

        $this->assertCount(6, $pairs);
    }

    public function test_each_pair_has_exactly_two_members(): void
    {
        $pairs = VibeFlag::mutuallyExclusivePairs();

        foreach ($pairs as $index => $pair) {
            $this->assertCount(2, $pair, "Pair at index {$index} does not have exactly 2 members");
        }
    }

    public function test_both_members_of_each_pair_are_valid_vibe_flags(): void
    {
        $allValues = array_column(VibeFlag::cases(), 'value');
        $pairs = VibeFlag::mutuallyExclusivePairs();

        foreach ($pairs as $index => $pair) {
            $this->assertInstanceOf(VibeFlag::class, $pair[0], "Pair {$index} first member is not a VibeFlag");
            $this->assertInstanceOf(VibeFlag::class, $pair[1], "Pair {$index} second member is not a VibeFlag");
            $this->assertContains($pair[0]->value, $allValues, "Pair {$index} first member is not a valid VibeFlag value");
            $this->assertContains($pair[1]->value, $allValues, "Pair {$index} second member is not a valid VibeFlag value");
        }
    }

    public function test_pairs_are_symmetric_where_possible(): void
    {
        $pairs = VibeFlag::mutuallyExclusivePairs();

        // Build a lookup: each flag should see its partner
        // Note: a flag may appear in multiple pairs (e.g. FamilyFriendly opposes both Horror and MatureThemes).
        // For flags in exactly one pair, verify their partner points back to them.
        $flagPairCount = [];
        foreach ($pairs as [$a, $b]) {
            $flagPairCount[$a->value] = ($flagPairCount[$a->value] ?? 0) + 1;
            $flagPairCount[$b->value] = ($flagPairCount[$b->value] ?? 0) + 1;
        }

        foreach ($pairs as [$a, $b]) {
            // For each pair, A and B should appear together in at least one pair
            $this->assertTrue(
                collect($pairs)->some(fn ($p) =>
                    ($p[0]->value === $a->value && $p[1]->value === $b->value) ||
                    ($p[0]->value === $b->value && $p[1]->value === $a->value)
                ),
                "Pair [{$a->value}, {$b->value}] should be listed together"
            );
        }
    }

    public function test_family_friendly_correctly_paired_with_both_opposites(): void
    {
        // FamilyFriendly appears in two pairs (Horror, MatureThemes) — this is intentional.
        // Verify it has exactly those two partners and no others.
        $pairs = VibeFlag::mutuallyExclusivePairs();
        $ffPartners = [];

        foreach ($pairs as [$a, $b]) {
            if ($a->value === 'family-friendly') {
                $ffPartners[] = $b->value;
            }
            if ($b->value === 'family-friendly') {
                $ffPartners[] = $a->value;
            }
        }

        sort($ffPartners);
        $this->assertEquals(['horror', 'mature-themes'], $ffPartners);
    }

    public function test_both_members_are_different(): void
    {
        $pairs = VibeFlag::mutuallyExclusivePairs();

        foreach ($pairs as $index => [$a, $b]) {
            $this->assertNotSame($a, $b, "Pair {$index} has identical members");
            $this->assertNotEquals($a->value, $b->value, "Pair {$index} members have the same value");
        }
    }

    // ── Specific pair assertions ──────────────────────

    public function test_lighthearted_and_serious_are_paired(): void
    {
        $this->assertPairExists(VibeFlag::Lighthearted, VibeFlag::Serious);
    }

    public function test_horror_and_family_friendly_are_paired(): void
    {
        $this->assertPairExists(VibeFlag::Horror, VibeFlag::FamilyFriendly);
    }

    public function test_mature_themes_and_family_friendly_are_paired(): void
    {
        $this->assertPairExists(VibeFlag::MatureThemes, VibeFlag::FamilyFriendly);
    }

    public function test_rules_light_and_rules_heavy_are_paired(): void
    {
        $this->assertPairExists(VibeFlag::RulesLight, VibeFlag::RulesHeavy);
    }

    public function test_combat_focused_and_roleplay_heavy_are_paired(): void
    {
        $this->assertPairExists(VibeFlag::CombatFocused, VibeFlag::RoleplayHeavy);
    }

    public function test_competitive_and_cooperative_are_paired(): void
    {
        $this->assertPairExists(VibeFlag::Competitive, VibeFlag::Cooperative);
    }

    // ── Helpers ────────────────────────────────────────

    private function assertPairExists(VibeFlag $a, VibeFlag $b): void
    {
        $pairs = VibeFlag::mutuallyExclusivePairs();
        $found = false;

        foreach ($pairs as $pair) {
            $values = [$pair[0]->value, $pair[1]->value];
            if (in_array($a->value, $values) && in_array($b->value, $values)) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, "Expected pair [{$a->value}, {$b->value}] not found in mutually exclusive pairs");
    }
}

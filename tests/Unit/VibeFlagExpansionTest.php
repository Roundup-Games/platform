<?php

namespace Tests\Unit;

use App\Enums\VibeFlag;
use Illuminate\Support\Facades\App;
use Tests\TestCase;
use Tests\Traits\SetsUpLocale;

class VibeFlagExpansionTest extends TestCase
{
    use SetsUpLocale;

    // ── Translation coverage (guards against missing translation keys) ──

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

        App::setLocale('en');
    }

    // ── Structural consistency (grouped covers all cases, no duplicates) ──

    public function test_every_flag_appears_in_exactly_one_group(): void
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

        $this->assertCount(count(VibeFlag::cases()), $seen, 'Not all flags appear in grouped() output');
    }
}

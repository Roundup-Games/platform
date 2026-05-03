<?php

namespace Tests\Unit;

use App\Enums\VibeFlag;
use Illuminate\Support\Facades\App;
use Tests\TestCase;
use Tests\Traits\SetsUpLocale;

class VibeFlagExpansionTest extends TestCase
{
    use SetsUpLocale;

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
}

<?php

namespace App\Enums;

enum SafetyTool: string
{
    // Before the Game
    case SessionZero = 'session-zero';
    case LinesAndVeils = 'lines-and-veils';
    case OpenDoor = 'open-door';

    // During the Game
    case XCard = 'x-card';
    case XnoCard = 'xno-card';
    case ScriptChange = 'script-change';
    case Breaks = 'breaks';

    // After the Game
    case StarsAndWishes = 'stars-and-wishes';
    case Debriefing = 'debriefing';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::SessionZero => 'Session Zero',
            self::LinesAndVeils => 'Lines & Veils',
            self::OpenDoor => 'Open Door Policy',
            self::XCard => 'X-Card',
            self::XnoCard => 'X·No Card',
            self::ScriptChange => 'Script Change',
            self::Breaks => 'Breaks',
            self::StarsAndWishes => 'Stars & Wishes',
            self::Debriefing => 'Debriefing',
        };
    }

    public function shortDescription(): string
    {
        return match ($this) {
            self::SessionZero => 'A pre-game session to set expectations, discuss boundaries, and build trust.',
            self::LinesAndVeils => 'Define hard limits (lines) and fade-to-black topics (veils) before play.',
            self::OpenDoor => 'Anyone can leave the game at any time, no questions asked.',
            self::XCard => 'Tap the X-card to immediately pause or skip content without explanation.',
            self::XnoCard => 'A two-card system: X to pause content, N to request more of something.',
            self::ScriptChange => 'Use movie-style ratings (FF, RW, BR) to adjust scene intensity.',
            self::Breaks => 'Scheduled or on-demand breaks for decompression during play.',
            self::StarsAndWishes => 'Highlight what worked (stars) and suggest changes (wishes) after a session.',
            self::Debriefing => 'A structured post-game conversation about emotional impact and experience.',
        };
    }

    public function fullDescription(): string
    {
        return match ($this) {
            self::SessionZero => 'A dedicated session before gameplay begins where the group discusses the game\'s tone, themes, boundaries, and expectations. It establishes a foundation of trust and ensures everyone is comfortable with the direction of the game. Session Zero is widely considered the single most impactful safety practice in tabletop roleplaying.',
            self::LinesAndVeils => 'A tool for establishing content boundaries before and during play. "Lines" are topics that will not appear in the game at all (hard limits). "Veils" are topics that may happen but only off-screen or with a fade-to-black (soft limits). This framework allows players to communicate their comfort levels clearly without having to explain why.',
            self::OpenDoor => 'A foundational safety practice that establishes an explicit policy: any player can leave the table at any time, for any reason, without judgment or questions. This removes social pressure to stay in uncomfortable situations and reinforces that the game is always voluntary.',
            self::XCard => 'Created by John Stavropoulos, the X-Card is a simple tool: a card with an "X" placed on the table. Any player can tap or hold up the card at any time to indicate that they want the current scene or topic to change immediately — no explanation required. The group moves on without questioning the request.',
            self::XnoCard => 'An extension of the X-Card concept that adds a second card: the N (No) card. The X card signals "pause or change this" and the N card signals "I want more of this." This dual system gives players a way to express both boundaries and enthusiasm, creating richer communication during play.',
            self::ScriptChange => 'Created by Beau Jágr Sheldon, Script Change uses familiar movie ratings as a communication tool during play. Players can request "FF" (fast-forward — skip past this), "RW" (rewind — redo this differently), or "BR" (brake — slow down, build up to this gradually). It provides multiple levels of content adjustment beyond a simple stop.',
            self::Breaks => 'Regular breaks during gameplay provide space for players to decompress, process emotions, and reset. Breaks can be scheduled at set intervals or called by any player at any time. They are especially important during intense or emotionally heavy scenes, and work well alongside other safety tools.',
            self::StarsAndWishes => 'A reflective tool for the end of a session or campaign. Each player shares "Stars" — moments they particularly enjoyed or appreciated — and "Wishes" — things they\'d like to see changed or explored in future sessions. This builds a positive feedback loop and helps the group calibrate ongoing play.',
            self::Debriefing => 'A structured post-game conversation that goes beyond casual feedback. Players discuss the emotional impact of the session, check in on each other\'s well-being, and address any lingering feelings from intense scenes. Debriefing is especially valuable after emotionally heavy gameplay and helps prevent negative experiences from compounding over time.',
        };
    }

    public function category(): SafetyToolCategory
    {
        return match ($this) {
            self::SessionZero, self::LinesAndVeils, self::OpenDoor => SafetyToolCategory::Before,
            self::XCard, self::XnoCard, self::ScriptChange, self::Breaks => SafetyToolCategory::During,
            self::StarsAndWishes, self::Debriefing => SafetyToolCategory::After,
        };
    }

    public function supportsText(): bool
    {
        return $this === self::LinesAndVeils;
    }

    public function textPlaceholder(): string
    {
        return match ($this) {
            self::LinesAndVeils => 'e.g. No spiders, fade-to-black for romance',
            default => '',
        };
    }

    /**
     * Grouped options for UI display, keyed by category.
     *
     * @return array<string, array{label: string, options: array<string, string>}>
     */
    public static function grouped(): array
    {
        $result = [];

        foreach (SafetyToolCategory::cases() as $category) {
            $options = [];
            foreach (self::cases() as $tool) {
                if ($tool->category() === $category) {
                    $options[$tool->value] = $tool->label();
                }
            }
            $result[$category->value] = [
                'label' => $category->label(),
                'options' => $options,
            ];
        }

        return $result;
    }

    /**
     * The recommended starter set of safety tools for new groups.
     *
     * @return self[]
     */
    public static function recommended(): array
    {
        return [
            self::SessionZero,
            self::LinesAndVeils,
            self::XCard,
            self::OpenDoor,
            self::Breaks,
        ];
    }
}

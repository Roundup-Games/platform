<?php

namespace App\Enums;

enum VibeFlag: string
{
    // Tone & Atmosphere
    case Atmospheric = 'atmospheric';
    case Lighthearted = 'lighthearted';
    case Serious = 'serious';
    case Horror = 'horror';
    case Humorous = 'humorous';

    // Content
    case MatureThemes = 'mature-themes';
    case FamilyFriendly = 'family-friendly';
    case CharacterDriven = 'character-driven';
    case StoryRich = 'story-rich';

    // Playstyle
    case RulesLight = 'rules-light';
    case RulesHeavy = 'rules-heavy';
    case Tactical = 'tactical';
    case CombatFocused = 'combat-focused';
    case RoleplayHeavy = 'roleplay-heavy';
    case Exploration = 'exploration';
    case PuzzleSolving = 'puzzle-solving';

    // TTRPG Playstyle
    case Sandbox = 'sandbox';
    case RuleOfCool = 'rule-of-cool';
    case DungeonCrawl = 'dungeon-crawl';
    case KingdomBuilding = 'kingdom-building';
    case TheaterOfTheMind = 'theater-of-the-mind';
    case RulesAsWritten = 'rules-as-written';
    case RoleplayLight = 'roleplay-light';

    // TTRPG Format
    case PlayByPost = 'play-by-post';
    case OrganizedPlay = 'organized-play';
    case WestMarches = 'west-marches';

    // Social
    case Competitive = 'competitive';
    case Cooperative = 'cooperative';
    case NewPlayerFriendly = 'new-player-friendly';
    case DropInFriendly = 'drop-in-friendly';

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
            self::Atmospheric => __('discovery.content_atmospheric'),
            self::Lighthearted => __('discovery.content_lighthearted'),
            self::Serious => __('discovery.content_serious'),
            self::Horror => __('discovery.content_horror'),
            self::Humorous => __('discovery.content_humorous'),
            self::MatureThemes => __('discovery.content_mature_themes'),
            self::FamilyFriendly => __('discovery.field_family_friendly'),
            self::CharacterDriven => __('discovery.content_character_driven'),
            self::StoryRich => __('discovery.content_story_rich'),
            self::RulesLight => __('discovery.content_rules_light'),
            self::RulesHeavy => __('discovery.content_rules_heavy'),
            self::Tactical => __('discovery.content_tactical'),
            self::CombatFocused => __('discovery.content_combat_focused'),
            self::RoleplayHeavy => __('discovery.content_roleplay_heavy'),
            self::Exploration => __('discovery.content_exploration'),
            self::PuzzleSolving => __('discovery.content_puzzle_solving'),
            self::Competitive => __('discovery.content_competitive'),
            self::Cooperative => __('discovery.content_cooperative'),
            self::NewPlayerFriendly => __('discovery.field_new_player_friendly'),
            self::DropInFriendly => __('discovery.field_drop_in_friendly'),
            self::Sandbox => __('discovery.content_sandbox'),
            self::RuleOfCool => __('discovery.content_rule_of_cool'),
            self::DungeonCrawl => __('discovery.content_dungeon_crawl'),
            self::KingdomBuilding => __('discovery.content_kingdom_building'),
            self::TheaterOfTheMind => __('discovery.content_theater_of_the_mind'),
            self::RulesAsWritten => __('discovery.content_rules_as_written'),
            self::RoleplayLight => __('discovery.content_roleplay_light'),
            self::PlayByPost => __('discovery.content_play_by_post'),
            self::OrganizedPlay => __('discovery.content_organized_play'),
            self::WestMarches => __('discovery.content_west_marches'),
        };
    }

    /**
     * Pairs of semantically opposed vibes. When one is favorited,
     * the other is auto-avoided (but NOT vice versa).
     *
     * @return array<int, array{0: VibeFlag, 1: VibeFlag}>
     */
    public static function mutuallyExclusivePairs(): array
    {
        return [
            [self::Lighthearted, self::Serious],
            [self::Horror, self::FamilyFriendly],
            [self::MatureThemes, self::FamilyFriendly],
            [self::RulesLight, self::RulesHeavy],
            [self::CombatFocused, self::RoleplayHeavy],
            [self::Competitive, self::Cooperative],
            [self::RuleOfCool, self::RulesHeavy],
            [self::RoleplayLight, self::RoleplayHeavy],
        ];
    }

    /**
     * Returns VibeFlag cases relevant to a game type.
     *
     * - 'board_game' → shared flags (tone, content subset, playstyle subset, social)
     * - 'ttrpg'      → all 30 cases (no filtering)
     * - unknown      → all cases (safe fallback)
     *
     * @return VibeFlag[]
     */
    public static function forGameType(string $gameType): array
    {
        if ($gameType === 'board_game') {
            return [
                // Tone
                self::Atmospheric, self::Lighthearted, self::Serious, self::Horror, self::Humorous,
                // Content
                self::FamilyFriendly, self::MatureThemes,
                // Playstyle
                self::RulesLight, self::RulesHeavy, self::Tactical, self::PuzzleSolving,
                // Social
                self::Competitive, self::Cooperative, self::NewPlayerFriendly, self::DropInFriendly,
            ];
        }

        // ttrpg and unknown → all cases
        return self::cases();
    }

    /**
     * Grouped options for UI display, filtered by game type.
     *
     * Same structure as grouped() but only includes flags from forGameType().
     * Empty groups are omitted.
     *
     * @return array<string, array{label: string, options: array<string, string>}>
     */
    public static function groupedForGameType(string $gameType): array
    {
        $allowed = collect(self::forGameType($gameType))->keyBy(fn (VibeFlag $f) => $f->value);
        $allGrouped = self::grouped();

        $result = [];
        foreach ($allGrouped as $groupKey => $group) {
            $filteredOptions = [];
            foreach ($group['options'] as $value => $label) {
                if ($allowed->has($value)) {
                    $filteredOptions[$value] = $label;
                }
            }
            if (! empty($filteredOptions)) {
                $result[$groupKey] = [
                    'label' => $group['label'],
                    'options' => $filteredOptions,
                ];
            }
        }

        return $result;
    }

    /**
     * Grouped options for UI display.
     *
     * @return array<string, array{label: string, options: array<string, string>}>
     */
    public static function grouped(): array
    {
        $groups = [
            'tone' => [
                'label' => __('discovery.content_tone_atmosphere'),
                'flags' => [self::Atmospheric, self::Lighthearted, self::Serious, self::Horror, self::Humorous],
            ],
            'content' => [
                'label' => __('discovery.content_content'),
                'flags' => [self::MatureThemes, self::FamilyFriendly, self::CharacterDriven, self::StoryRich],
            ],
            'playstyle' => [
                'label' => __('discovery.content_playstyle'),
                'flags' => [self::RulesLight, self::RulesHeavy, self::Tactical, self::CombatFocused, self::RoleplayHeavy, self::Exploration, self::PuzzleSolving, self::Sandbox, self::RuleOfCool, self::DungeonCrawl, self::KingdomBuilding, self::TheaterOfTheMind, self::RulesAsWritten, self::RoleplayLight],
            ],
            'social' => [
                'label' => __('discovery.content_social'),
                'flags' => [self::Competitive, self::Cooperative, self::NewPlayerFriendly, self::DropInFriendly],
            ],
            'format' => [
                'label' => __('discovery.content_format'),
                'flags' => [self::PlayByPost, self::OrganizedPlay, self::WestMarches],
            ],
        ];

        $result = [];
        foreach ($groups as $key => $group) {
            $options = [];
            foreach ($group['flags'] as $flag) {
                $options[$flag->value] = $flag->label();
            }
            $result[$key] = [
                'label' => $group['label'],
                'options' => $options,
            ];
        }

        return $result;
    }
}

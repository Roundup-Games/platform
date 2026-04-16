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
            self::Atmospheric => 'Atmospheric',
            self::Lighthearted => 'Lighthearted',
            self::Serious => 'Serious',
            self::Horror => 'Horror',
            self::Humorous => 'Humorous',
            self::MatureThemes => 'Mature Themes',
            self::FamilyFriendly => 'Family-Friendly',
            self::CharacterDriven => 'Character-Driven',
            self::StoryRich => 'Story-Rich',
            self::RulesLight => 'Rules-Light',
            self::RulesHeavy => 'Rules-Heavy',
            self::Tactical => 'Tactical',
            self::CombatFocused => 'Combat-Focused',
            self::RoleplayHeavy => 'Roleplay-Heavy',
            self::Exploration => 'Exploration',
            self::PuzzleSolving => 'Puzzle-Solving',
            self::Competitive => 'Competitive',
            self::Cooperative => 'Cooperative',
            self::NewPlayerFriendly => 'New-Player-Friendly',
            self::DropInFriendly => 'Drop-In Friendly',
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
        ];
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
                'label' => 'Tone & Atmosphere',
                'flags' => [self::Atmospheric, self::Lighthearted, self::Serious, self::Horror, self::Humorous],
            ],
            'content' => [
                'label' => 'Content',
                'flags' => [self::MatureThemes, self::FamilyFriendly, self::CharacterDriven, self::StoryRich],
            ],
            'playstyle' => [
                'label' => 'Playstyle',
                'flags' => [self::RulesLight, self::RulesHeavy, self::Tactical, self::CombatFocused, self::RoleplayHeavy, self::Exploration, self::PuzzleSolving],
            ],
            'social' => [
                'label' => 'Social',
                'flags' => [self::Competitive, self::Cooperative, self::NewPlayerFriendly, self::DropInFriendly],
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

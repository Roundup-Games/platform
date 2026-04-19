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
            self::SessionZero => __('safety.tool_session_zero'),
            self::LinesAndVeils => __('safety.tool_lines_and_veils'),
            self::OpenDoor => __('safety.tool_open_door'),
            self::XCard => __('safety.tool_x_card'),
            self::XnoCard => __('safety.tool_xno_card'),
            self::ScriptChange => __('safety.tool_script_change'),
            self::Breaks => __('safety.tool_breaks'),
            self::StarsAndWishes => __('safety.tool_stars_and_wishes'),
            self::Debriefing => __('safety.tool_debriefing'),
        };
    }

    public function shortDescription(): string
    {
        return match ($this) {
            self::SessionZero => __('safety.tool_session_zero_short'),
            self::LinesAndVeils => __('safety.tool_lines_and_veils_short'),
            self::OpenDoor => __('safety.tool_open_door_short'),
            self::XCard => __('safety.tool_x_card_short'),
            self::XnoCard => __('safety.tool_xno_card_short'),
            self::ScriptChange => __('safety.tool_script_change_short'),
            self::Breaks => __('safety.tool_breaks_short'),
            self::StarsAndWishes => __('safety.tool_stars_and_wishes_short'),
            self::Debriefing => __('safety.tool_debriefing_short'),
        };
    }

    public function fullDescription(): string
    {
        return match ($this) {
            self::SessionZero => __('safety.tool_session_zero_full'),
            self::LinesAndVeils => __('safety.tool_lines_and_veils_full'),
            self::OpenDoor => __('safety.tool_open_door_full'),
            self::XCard => __('safety.tool_x_card_full'),
            self::XnoCard => __('safety.tool_xno_card_full'),
            self::ScriptChange => __('safety.tool_script_change_full'),
            self::Breaks => __('safety.tool_breaks_full'),
            self::StarsAndWishes => __('safety.tool_stars_and_wishes_full'),
            self::Debriefing => __('safety.tool_debriefing_full'),
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
            self::LinesAndVeils => __('safety.tool_lines_and_veils_placeholder'),
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

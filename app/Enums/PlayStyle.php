<?php

namespace App\Enums;

enum PlayStyle: string
{
    case NarrativeFirst = 'narrative-first';
    case Tactical = 'tactical';
    case OSR = 'osr';
    case Sandbox = 'sandbox';
    case Horror = 'horror';

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
            self::NarrativeFirst => __('discovery.playstyle_narrative_first'),
            self::Tactical => __('discovery.playstyle_tactical'),
            self::OSR => __('discovery.playstyle_osr'),
            self::Sandbox => __('discovery.playstyle_sandbox'),
            self::Horror => __('discovery.playstyle_horror'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::NarrativeFirst => __('discovery.playstyle_narrative_first_desc'),
            self::Tactical => __('discovery.playstyle_tactical_desc'),
            self::OSR => __('discovery.playstyle_osr_desc'),
            self::Sandbox => __('discovery.playstyle_sandbox_desc'),
            self::Horror => __('discovery.playstyle_horror_desc'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::NarrativeFirst => 'auto_stories',
            self::Tactical => 'target',
            self::OSR => 'shield',
            self::Sandbox => 'explore',
            self::Horror => 'visibility',
        };
    }

    /**
     * Category slugs that correspond to this play style.
     *
     * Used by the discovery filter to map a selected play style to
     * actual GameSystemCategory slugs. The mapping is editorial —
     * human judgment about which categories cluster together.
     *
     * Slugs must match GameSystemCategory.slug values in the database.
     *
     * @return string[]
     */
    public function categorySlugs(): array
    {
        return match ($this) {
            self::NarrativeFirst => [
                'imaginative',
                'romance',
                'mystery',
                'swashbuckling',
                'isekai',
                'heartwarming',
            ],
            self::Tactical => [
                'high-fantasy',
                'fantasy',
                'wargame',
                'miniatures',
                'political-intrigue',
                'fighting',
            ],
            self::OSR => [
                'grimdark',
                'gritty-fantasy',
                'survival',
                'dark-fantasy',
                'low-magic',
            ],
            self::Sandbox => [
                'universal',
                'exploration',
                'post-apocalyptic',
                'space-exploration',
                'pirate',
                'survival',
            ],
            self::Horror => [
                'horror',
                'eldritch-horror',
                'gothic-horror',
                'supernatural',
                'dark-fantasy',
                'grimdark',
            ],
        };
    }

    /**
     * Grouped options for UI display.
     *
     * Returns a single group with all play styles, their labels,
     * and their descriptions — ready for filter UI rendering.
     *
     * @return array<string, array{label: string, options: array<string, string>, descriptions: array<string, string>}>
     */
    public static function grouped(): array
    {
        $options = [];
        $descriptions = [];

        foreach (self::cases() as $style) {
            $options[$style->value] = $style->label();
            $descriptions[$style->value] = $style->description();
        }

        return [
            'play_styles' => [
                'label' => __('discovery.content_play_styles'),
                'options' => $options,
                'descriptions' => $descriptions,
            ],
        ];
    }
}

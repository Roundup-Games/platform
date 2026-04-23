<?php

namespace App\Enums;

enum GmProficiency: string
{
    case Creativity = 'creativity';
    case Inclusive = 'inclusive';
    case KnowsTheRules = 'knows-the-rules';
    case RuleOfCool = 'rule-of-cool';
    case SetsTheMood = 'sets-the-mood';
    case Storytelling = 'storytelling';
    case Teacher = 'teacher';
    case VisualAid = 'visual-aid';
    case Voices = 'voices';
    case WorldBuilder = 'world-builder';

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
            self::Creativity => __('profile.gm_proficiency_creativity'),
            self::Inclusive => __('profile.gm_proficiency_inclusive'),
            self::KnowsTheRules => __('profile.gm_proficiency_knows_the_rules'),
            self::RuleOfCool => __('profile.gm_proficiency_rule_of_cool'),
            self::SetsTheMood => __('profile.gm_proficiency_sets_the_mood'),
            self::Storytelling => __('profile.gm_proficiency_storytelling'),
            self::Teacher => __('profile.gm_proficiency_teacher'),
            self::VisualAid => __('profile.gm_proficiency_visual_aid'),
            self::Voices => __('profile.gm_proficiency_voices'),
            self::WorldBuilder => __('profile.gm_proficiency_world_builder'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Creativity => __('profile.gm_proficiency_creativity_desc'),
            self::Inclusive => __('profile.gm_proficiency_inclusive_desc'),
            self::KnowsTheRules => __('profile.gm_proficiency_knows_the_rules_desc'),
            self::RuleOfCool => __('profile.gm_proficiency_rule_of_cool_desc'),
            self::SetsTheMood => __('profile.gm_proficiency_sets_the_mood_desc'),
            self::Storytelling => __('profile.gm_proficiency_storytelling_desc'),
            self::Teacher => __('profile.gm_proficiency_teacher_desc'),
            self::VisualAid => __('profile.gm_proficiency_visual_aid_desc'),
            self::Voices => __('profile.gm_proficiency_voices_desc'),
            self::WorldBuilder => __('profile.gm_proficiency_world_builder_desc'),
        };
    }
}

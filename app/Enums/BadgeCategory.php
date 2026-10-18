<?php

namespace App\Enums;

enum BadgeCategory: string
{
    case SupportTenure = 'support_tenure';
    case CommunityInfrastructure = 'community_infrastructure';
    case GmCraft = 'gm_craft';
    case Participation = 'participation';
    case EventsOrganizing = 'events_organizing';
    case Milestones = 'milestones';

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
            self::SupportTenure => __('badges.category_support_tenure'),
            self::CommunityInfrastructure => __('badges.category_community_infrastructure'),
            self::GmCraft => __('badges.category_gm_craft'),
            self::Participation => __('badges.category_participation'),
            self::EventsOrganizing => __('badges.category_events_organizing'),
            self::Milestones => __('badges.category_milestones'),
        };
    }
}

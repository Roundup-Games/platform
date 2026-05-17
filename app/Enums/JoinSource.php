<?php

namespace App\Enums;

enum JoinSource: string
{
    case FriendInvite = 'friend_invite';
    case ShareLink = 'share_link';
    case Application = 'application';
    case EmailInvite = 'email_invite';
    case ShortLink = 'short_link';

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
            self::FriendInvite => __('common.content_source_friend_invite'),
            self::ShareLink => __('common.content_source_share_link'),
            self::Application => __('common.content_source_application'),
            self::EmailInvite => __('common.content_source_email_invite'),
            self::ShortLink => __('common.content_source_short_link'),
        };
    }
}

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
            self::FriendInvite => 'Friend Invite',
            self::ShareLink => 'Share Link',
            self::Application => 'Application',
            self::EmailInvite => 'Email Invite',
            self::ShortLink => 'Short Link',
        };
    }
}

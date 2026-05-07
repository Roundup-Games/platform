<?php

namespace App\Enums;

enum JoinSource: string
{
    case FriendInvite = 'friend_invite';
    case ShareLink = 'share_link';
    case Application = 'application';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

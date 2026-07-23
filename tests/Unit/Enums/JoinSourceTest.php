<?php

use App\Enums\JoinSource;

describe('JoinSource enum', function () {
    it('has correct values', function () {
        expect(JoinSource::FriendInvite->value)->toBe('friend_invite');
        expect(JoinSource::ShareLink->value)->toBe('share_link');
        expect(JoinSource::Application->value)->toBe('application');
        expect(JoinSource::EmailInvite->value)->toBe('email_invite');
        expect(JoinSource::ShortLink->value)->toBe('short_link');
        expect(JoinSource::Discord->value)->toBe('discord');
    });

    it('returns all values via values()', function () {
        $values = JoinSource::values();

        expect($values)->toBe(['friend_invite', 'share_link', 'application', 'email_invite', 'short_link', 'discord']);
    });

    it('can be created from string value', function () {
        expect(JoinSource::from('friend_invite'))->toBe(JoinSource::FriendInvite);
        expect(JoinSource::from('share_link'))->toBe(JoinSource::ShareLink);
        expect(JoinSource::from('application'))->toBe(JoinSource::Application);
        expect(JoinSource::from('email_invite'))->toBe(JoinSource::EmailInvite);
        expect(JoinSource::from('short_link'))->toBe(JoinSource::ShortLink);
        expect(JoinSource::from('discord'))->toBe(JoinSource::Discord);
    });
});

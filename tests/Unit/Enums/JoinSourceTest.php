<?php

use App\Enums\JoinSource;

describe('JoinSource enum', function () {
    it('has three cases', function () {
        expect(JoinSource::cases())->toHaveCount(3);
    });

    it('has correct values', function () {
        expect(JoinSource::FriendInvite->value)->toBe('friend_invite');
        expect(JoinSource::ShareLink->value)->toBe('share_link');
        expect(JoinSource::Application->value)->toBe('application');
    });

    it('returns all values via values()', function () {
        $values = JoinSource::values();

        expect($values)->toBe(['friend_invite', 'share_link', 'application']);
    });

    it('returns human-readable labels via label()', function () {
        expect(JoinSource::FriendInvite->label())->toBe('Friend Invite');
        expect(JoinSource::ShareLink->label())->toBe('Share Link');
        expect(JoinSource::Application->label())->toBe('Application');
    });

    it('can be created from string value', function () {
        expect(JoinSource::from('friend_invite'))->toBe(JoinSource::FriendInvite);
        expect(JoinSource::from('share_link'))->toBe(JoinSource::ShareLink);
        expect(JoinSource::from('application'))->toBe(JoinSource::Application);
    });
});

<?php

use App\Enums\JoinSource;

describe('JoinSource enum', function () {
    it('returns all values via values()', function () {
        $values = JoinSource::values();

        expect($values)->toBe(['friend_invite', 'share_link', 'application', 'email_invite', 'short_link', 'discord']);
    });

});

<?php

use function Pest\Laravel\get;

// The game_system_id filter binds to a uuid column; a malformed value from
// a shared link or probe must be "no filter" (uuid_or_null), never a 22P02.
describe('GmDirectory', function () {
    it('renders for guests', function () {
        get(route('gm.directory'))->assertOk();
    });

    it('ignores a malformed uuid game system filter', function () {
        get(route('gm.directory', ['game_system_id' => "1' OR '1'='1"]))
            ->assertOk();
    });
});

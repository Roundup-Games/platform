<?php

use App\Models\User;

describe('Authentication', function () {
    test('logout clears site data cache', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect()
            ->assertHeader('Clear-Site-Data', '"cache", "storage"');
    });
});

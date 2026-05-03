<?php

use App\Livewire\Discovery\DiscoveryPortal;
use function Pest\Laravel\get;

describe('DiscoveryPortal – rendering', function () {
    it('renders at /discover for guests', function () {
        Livewire\Livewire::test(DiscoveryPortal::class)
            ->assertOk();
    })->group('smoke');

    it('is accessible via named route discover', function () {
        get(route('discover'))->assertOk();
    });
});

<?php

use App\Models\User;
use Carbon\Carbon;

describe('trial_ends_at column type', function () {
    test('trial_ends_at stores and retrieves as Carbon', function () {
        $user = User::factory()->create([
            'trial_ends_at' => null,
        ]);

        $trialDate = Carbon::parse('2026-06-01 12:30:00');
        $user->trial_ends_at = $trialDate;
        $user->save();

        $fresh = User::find($user->id);

        expect($fresh->trial_ends_at)->toBeInstanceOf(Carbon::class);
        expect($fresh->trial_ends_at->format('Y-m-d H:i:s'))->toBe('2026-06-01 12:30:00');
    });

    test('trial_ends_at accepts null value', function () {
        $user = User::factory()->create([
            'trial_ends_at' => Carbon::now(),
        ]);

        $user->trial_ends_at = null;
        $user->save();

        $fresh = User::find($user->id);
        expect($fresh->trial_ends_at)->toBeNull();
    });

    test('trial_ends_at is queryable with date comparisons', function () {
        $pastUser = User::factory()->create(['trial_ends_at' => Carbon::parse('2025-01-01')]);
        $futureUser = User::factory()->create(['trial_ends_at' => Carbon::parse('2027-01-01')]);
        $nullUser = User::factory()->create(['trial_ends_at' => null]);

        $activeTrials = User::where('trial_ends_at', '>', Carbon::parse('2026-01-01'))->get();

        expect($activeTrials)->toHaveCount(1);
        expect($activeTrials->first()->id)->toBe($futureUser->id);
    });
});

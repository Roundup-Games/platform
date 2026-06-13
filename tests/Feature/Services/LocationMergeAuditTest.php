<?php

use App\Models\Location;
use App\Models\User;
use App\Services\LocationMergeService;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->service = app(LocationMergeService::class);
    $this->source = Location::factory()->create(['name' => 'Source Location']);
    $this->target = Location::factory()->create(['name' => 'Target Location']);
});

// ── Finding 6: Parameterized actedBy user ──

describe('actedBy user parameter', function () {
    test('merge accepts explicit acting user', function () {
        $admin = User::factory()->create();
        Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) use ($admin) {
            return $message === 'Location merge completed'
                && $context['merged_by'] === $admin->id;
        });

        $this->service->merge($this->source, $this->target, $admin);
    });

    test('merge falls back to auth user when actedBy is null', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) use ($user) {
            return $message === 'Location merge completed'
                && $context['merged_by'] === $user->id;
        });

        $this->service->merge($this->source, $this->target);
    });

    test('merge logs null merged_by when no user in context', function () {
        Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) {
            return $message === 'Location merge completed'
                && $context['merged_by'] === null;
        });

        $this->service->merge($this->source, $this->target, null);
    });
});

describe('self-merge guard', function () {
    test('merge rejects merging a location into itself', function () {
        expect(fn () => $this->service->merge($this->source, $this->source))
            ->toThrow(InvalidArgumentException::class, 'Cannot merge a location into itself');
    });
});

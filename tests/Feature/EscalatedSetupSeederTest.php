<?php

use Database\Seeders\EscalatedSetupSeeder;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\SlaPolicy;
use Escalated\Laravel\Models\Tag;

beforeEach(function () {
    $this->seeder = new EscalatedSetupSeeder();
});

describe('departments', function () {
    it('seeds all 6 departments', function () {
        $this->seeder->run();

        expect(Department::count())->toBe(6);
    });

    it('creates Contact department', function () {
        $this->seeder->run();

        $dept = Department::where('name', 'Contact')->first();
        expect($dept)->not->toBeNull()
            ->and($dept->slug)->toBe('contact')
            ->and($dept->description)->toBe('General inquiries and questions')
            ->and($dept->is_active)->toBeTrue();
    });

    it('creates Game Systems department', function () {
        $this->seeder->run();

        $dept = Department::where('name', 'Game Systems')->first();
        expect($dept)->not->toBeNull()
            ->and($dept->slug)->toBe('game-systems')
            ->and($dept->is_active)->toBeTrue();
    });

    it('creates Safety department', function () {
        $this->seeder->run();

        $dept = Department::where('name', 'Safety')->first();
        expect($dept)->not->toBeNull()
            ->and($dept->slug)->toBe('safety')
            ->and($dept->is_active)->toBeTrue();
    });

    it('creates Events department', function () {
        $this->seeder->run();

        $dept = Department::where('name', 'Events')->first();
        expect($dept)->not->toBeNull()
            ->and($dept->slug)->toBe('events')
            ->and($dept->is_active)->toBeTrue();
    });

    it('creates Billing department', function () {
        $this->seeder->run();

        $dept = Department::where('name', 'Billing')->first();
        expect($dept)->not->toBeNull()
            ->and($dept->slug)->toBe('billing')
            ->and($dept->is_active)->toBeTrue();
    });

    it('creates Account Support department', function () {
        $this->seeder->run();

        $dept = Department::where('name', 'Account Support')->first();
        expect($dept)->not->toBeNull()
            ->and($dept->slug)->toBe('account-support')
            ->and($dept->is_active)->toBeTrue();
    });

    it('is idempotent — running twice does not duplicate records', function () {
        $this->seeder->run();
        $this->seeder->run();

        expect(Department::count())->toBe(6);
    });
});

describe('tags', function () {
    it('seeds all 9 tags', function () {
        $this->seeder->run();

        expect(Tag::count())->toBe(9);
    });

    it('creates bug tag with red color', function () {
        $this->seeder->run();

        $tag = Tag::where('name', 'bug')->first();
        expect($tag)->not->toBeNull()
            ->and($tag->slug)->toBe('bug')
            ->and($tag->color)->toBe('#EF4444');
    });

    it('creates feature-request tag', function () {
        $this->seeder->run();

        $tag = Tag::where('name', 'feature-request')->first();
        expect($tag)->not->toBeNull()
            ->and($tag->slug)->toBe('feature-request')
            ->and($tag->color)->toBe('#3B82F6');
    });

    it('creates all safety-related tags', function () {
        $this->seeder->run();

        $safetyNames = ['inappropriate-content', 'harassment', 'spam'];
        foreach ($safetyNames as $name) {
            expect(Tag::where('name', $name)->exists())->toBeTrue("Tag '{$name}' should exist");
        }
    });

    it('creates bgg-sync tag', function () {
        $this->seeder->run();

        $tag = Tag::where('name', 'bgg-sync')->first();
        expect($tag)->not->toBeNull()
            ->and($tag->color)->toBe('#059669');
    });

    it('is idempotent — running twice does not duplicate records', function () {
        $this->seeder->run();
        $this->seeder->run();

        expect(Tag::count())->toBe(9);
    });
});

describe('SLA policies', function () {
    it('seeds all 6 SLA policies', function () {
        $this->seeder->run();

        expect(SlaPolicy::count())->toBe(6);
    });

    it('creates Safety SLA with fastest response times', function () {
        $this->seeder->run();

        $policy = SlaPolicy::where('name', 'Safety SLA')->first();
        expect($policy)->not->toBeNull()
            ->and($policy->business_hours_only)->toBeFalse()
            ->and($policy->is_active)->toBeTrue()
            ->and($policy->first_response_hours['medium'])->toBe(4)
            ->and($policy->resolution_hours['medium'])->toBe(24);
    });

    it('creates Billing SLA with 24h first response for medium priority', function () {
        $this->seeder->run();

        $policy = SlaPolicy::where('name', 'Billing SLA')->first();
        expect($policy)->not->toBeNull()
            ->and($policy->first_response_hours['medium'])->toBe(24)
            ->and($policy->resolution_hours['medium'])->toBe(72);
    });

    it('creates Contact SLA as default policy', function () {
        $this->seeder->run();

        $policy = SlaPolicy::where('name', 'Contact SLA')->first();
        expect($policy)->not->toBeNull()
            ->and($policy->is_default)->toBeTrue()
            ->and($policy->first_response_hours['medium'])->toBe(48)
            ->and($policy->resolution_hours['medium'])->toBe(120);
    });

    it('only Contact SLA is marked as default', function () {
        $this->seeder->run();

        expect(SlaPolicy::where('is_default', true)->count())->toBe(1);
        expect(SlaPolicy::where('is_default', true)->first()->name)->toBe('Contact SLA');
    });

    it('creates Game Systems SLA with longest response times', function () {
        $this->seeder->run();

        $policy = SlaPolicy::where('name', 'Game Systems SLA')->first();
        expect($policy)->not->toBeNull()
            ->and($policy->first_response_hours['medium'])->toBe(72)
            ->and($policy->resolution_hours['medium'])->toBe(168);
    });

    it('creates Events SLA with same times as Billing', function () {
        $this->seeder->run();

        $events = SlaPolicy::where('name', 'Events SLA')->first();
        $billing = SlaPolicy::where('name', 'Billing SLA')->first();
        expect($events->first_response_hours)->toEqual($billing->first_response_hours)
            ->and($events->resolution_hours)->toEqual($billing->resolution_hours);
    });

    it('creates Account Support SLA with 48h resolution for medium', function () {
        $this->seeder->run();

        $policy = SlaPolicy::where('name', 'Account Support SLA')->first();
        expect($policy)->not->toBeNull()
            ->and($policy->first_response_hours['medium'])->toBe(24)
            ->and($policy->resolution_hours['medium'])->toBe(48);
    });

    it('is idempotent — running twice does not duplicate records', function () {
        $this->seeder->run();
        $this->seeder->run();

        expect(SlaPolicy::count())->toBe(6);
    });

    it('all policies have all 5 priority levels defined', function () {
        $this->seeder->run();

        $priorities = ['low', 'medium', 'high', 'urgent', 'critical'];
        SlaPolicy::all()->each(function ($policy) use ($priorities) {
            foreach ($priorities as $p) {
                expect(array_key_exists($p, $policy->first_response_hours))
                    ->toBeTrue("{$policy->name} missing first_response_hours.{$p}");
                expect(array_key_exists($p, $policy->resolution_hours))
                    ->toBeTrue("{$policy->name} missing resolution_hours.{$p}");
            }
        });
    });
});

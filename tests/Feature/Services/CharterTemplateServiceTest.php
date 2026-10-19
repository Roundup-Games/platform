<?php

use App\Dto\CharterContent;
use App\Models\Campaign;
use App\Models\CharterTemplate;
use App\Models\SessionZeroSurvey;
use App\Models\User;
use App\Services\Charter\CharterService;
use App\Services\Charter\CharterTemplateService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Traits\CapturesLogRecords;
use Tests\Traits\CreatesUsers;

// M070/S04-T02 — CharterTemplateService: saved charter templates (R085
// member convenience). saveFromCharter snapshots a charter's normalized
// content for the member owner; listFor/delete are user_id-gated. Charters
// are built through the real lifecycle (start → publish).

uses(CapturesLogRecords::class, CreatesUsers::class);

/**
 * A fresh campaign owned by $owner with deterministic quick-start inputs.
 */
function charter_tpl_campaign(User $owner): Campaign
{
    return Campaign::factory()->create([
        'owner_id' => $owner->id,
        'name' => ['en' => 'The Threshold Heist'],
        'description' => ['en' => 'A noir intrigue campaign in the city of Threshold.'],
        'max_players' => 5,
        'session_duration' => 3,
        'recurrence' => 'weekly',
    ]);
}

/**
 * Publish an ACTIVE charter on a fresh campaign owned by $owner through
 * the real lifecycle (start → publish → refresh).
 */
function charter_tpl_published_charter(User $owner): SessionZeroSurvey
{
    $campaign = charter_tpl_campaign($owner);
    $charter = CharterService::start($owner, $campaign);

    $content = CharterContent::quickStart($campaign)->toArray();
    $content['overview']['expected_shape'] = 'ongoing';

    CharterService::publish($charter, (string) $campaign->name, $content);

    return $charter->refresh();
}

// ── saveFromCharter() ──────────────────────────────────

test('saveFromCharter snapshots normalized template content for the member owner', function () {
    $member = $this->createSubscribedGm();
    $charter = charter_tpl_published_charter($member);

    $template = CharterTemplateService::saveFromCharter($member, $charter, 'My standard charter');

    expect($template)->toBeInstanceOf(CharterTemplate::class)
        ->and($template->user_id)->toBe($member->id)
        ->and($template->name)->toBe('My standard charter')
        ->and($template->content_schema)->toBe(CharterContent::SCHEMA_VERSION)
        // Deep-equal normalized snapshot — the same shape start('template')
        // reseeds, so templates round-trip through fromArray unchanged.
        ->and($template->content)->toBe(CharterContent::fromArray($charter->content)->toArray())
        ->and($template->content['overview']['pitch'])->toBe('A noir intrigue campaign in the city of Threshold.');
});

test('saveFromCharter refuses anyone but the campaign owner', function () {
    $member = $this->createSubscribedGm();
    $charter = charter_tpl_published_charter($member);
    $otherMember = $this->createSubscribedGm();

    CharterTemplateService::saveFromCharter($otherMember, $charter, 'Not mine');
})->throws(AuthorizationException::class);

test('saveFromCharter refuses a free owner with the member-only error', function () {
    $owner = User::factory()->create();
    $charter = charter_tpl_published_charter($owner);

    try {
        CharterTemplateService::saveFromCharter($owner, $charter, 'Nope');
        $this->fail('Expected ValidationException for a free owner saving a template.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('charter')
            ->and($e->errors()['charter'][0])->toBe(__('charter.error_templates_member_only'));
    }

    expect(CharterTemplate::query()->whereBelongsTo($owner)->count())->toBe(0);
});

test('saveFromCharter validates the name: required and max 255', function () {
    $member = $this->createSubscribedGm();
    $charter = charter_tpl_published_charter($member);

    foreach (['', '   ', str_repeat('x', 256)] as $badName) {
        try {
            CharterTemplateService::saveFromCharter($member, $charter, $badName);
            $this->fail('Expected ValidationException for the template name.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('name');
        }
    }

    // A 255-char name is the boundary and passes.
    $template = CharterTemplateService::saveFromCharter($member, $charter, str_repeat('x', 255));

    expect($template->name)->toHaveLength(255);
});

test('saveFromCharter logs ids only, never content or name', function () {
    $this->captureLogRecords();

    $member = $this->createSubscribedGm();
    $charter = charter_tpl_published_charter($member);

    CharterTemplateService::saveFromCharter($member, $charter, 'Very Secret Template Name');

    $saved = $this->logRecord('Charter template saved');

    expect($saved)->not->toBeNull()
        ->and($saved['context'])->toHaveKeys(['charter_template_id', 'user_id', 'survey_id'])
        ->and($saved['context']['survey_id'])->toBe($charter->id)
        ->and($saved['context']['user_id'])->toBe($member->id);

    expect(json_encode($saved))->not->toContain('Very Secret Template Name');
});

// ── listFor() ──────────────────────────────────────────

test('listFor returns only that user\'s templates ordered by name', function () {
    $member = $this->createSubscribedGm();
    $otherMember = $this->createSubscribedGm();

    CharterTemplate::factory()->for($member)->create(['name' => 'Zealot charter']);
    CharterTemplate::factory()->for($member)->create(['name' => 'Baseline charter']);
    CharterTemplate::factory()->for($otherMember)->create(['name' => 'Not yours']);

    $templates = CharterTemplateService::listFor($member);

    expect($templates)->toHaveCount(2)
        ->and($templates->pluck('name')->all())->toBe(['Baseline charter', 'Zealot charter']);
});

// ── delete() ───────────────────────────────────────────

test('delete removes the owner\'s template and logs ids only', function () {
    $this->captureLogRecords();

    $member = $this->createSubscribedGm();
    $template = CharterTemplate::factory()->for($member)->create(['name' => 'Old standard']);

    expect(CharterTemplateService::delete($member, $template))->toBeTrue()
        ->and(CharterTemplate::query()->whereKey($template->id)->exists())->toBeFalse();

    $deleted = $this->logRecord('Charter template deleted');

    expect($deleted)->not->toBeNull()
        ->and($deleted['context'])->toHaveKeys(['charter_template_id', 'user_id'])
        ->and($deleted['context']['charter_template_id'])->toBe($template->id);
});

test('delete refuses another user\'s template', function () {
    $member = $this->createSubscribedGm();
    $template = CharterTemplate::factory()->for($this->createSubscribedGm())->create();

    CharterTemplateService::delete($member, $template);
})->throws(AuthorizationException::class);

<?php

use App\Models\User;
use Escalated\Laravel\Models\Article;
use Escalated\Laravel\Models\ArticleCategory;
use Escalated\Laravel\Models\AuditLog;
use Escalated\Laravel\Models\Automation;
use Escalated\Laravel\Models\BusinessSchedule;
use Escalated\Laravel\Models\CannedResponse;
use Escalated\Laravel\Models\CustomField;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\EscalationRule;
use Escalated\Laravel\Models\Macro;
use Escalated\Laravel\Models\Role;
use Escalated\Laravel\Models\Skill;
use Escalated\Laravel\Models\SlaPolicy;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Models\TicketStatus;
use Escalated\Laravel\Models\Webhook;
use Illuminate\Support\Facades\Gate;

/**
 * Role → expected Escalated capability matrix. '(no role)' is a regular
 * user with no role. Every looped assertion below reads its expected value
 * from this single table, so each old per-case intent maps to exactly one
 * keyed expectation in the consolidated tests.
 *
 * @return array<string, array{agent: bool, admin: bool, ticket: bool}>
 */
function escalatedVisibilityMatrix(): array
{
    return [
        'Platform Admin' => ['agent' => true, 'admin' => true, 'ticket' => true],
        'Service Admin' => ['agent' => true, 'admin' => false, 'ticket' => true],
        'Games Admin' => ['agent' => false, 'admin' => false, 'ticket' => false],
        'Team Admin' => ['agent' => false, 'admin' => false, 'ticket' => false],
        'Event Admin' => ['agent' => false, 'admin' => false, 'ticket' => false],
        '(no role)' => ['agent' => false, 'admin' => false, 'ticket' => false],
    ];
}

/**
 * Resources gated by the escalated-admin policy. Each entry used to be one
 * dataset case of the old 'Admin-only resource visibility' test; the
 * consolidated gate test loops this list so every model is still proven to
 * be individually wired to EscalatedAdminPolicy (a missing policy or a
 * permissive vendor default would surface as a mismatched cell).
 *
 * @return array<string, class-string>
 */
function escalatedAdminResources(): array
{
    return [
        'Department' => Department::class,
        'Tag' => Tag::class,
        'SLA Policy' => SlaPolicy::class,
        'Escalation Rule' => EscalationRule::class,
        'Canned Response' => CannedResponse::class,
        'Macro' => Macro::class,
        'Automation' => Automation::class,
        'Webhook' => Webhook::class,
        'Role' => Role::class,
        'Ticket Status' => TicketStatus::class,
        'Skill' => Skill::class,
        'Custom Field' => CustomField::class,
        'Business Schedule' => BusinessSchedule::class,
        'Article' => Article::class,
        'Article Category' => ArticleCategory::class,
        'Audit Log' => AuditLog::class,
    ];
}

beforeEach(function () {
    seedRoles();

    // One actor per matrix row per test. Looping the assertions over these
    // users replaces the old fan-out of one factory + assignRole per case:
    // 31 junit cases across 16 test declarations collapsed into 4 tests.
    $this->actors = [];
    foreach (array_keys(escalatedVisibilityMatrix()) as $role) {
        $user = User::factory()->create();
        if ($role !== '(no role)') {
            $user->assignRole($role);
        }
        $this->actors[$role] = $user;
    }
});

// ── Capability matrix (page visibility) ───────────────────────────────────
//
// Replaces the old 'Escalated page visibility' block (Reports accessible to
// Service/Platform Admin, not to Games Admin; Settings require
// escalated-admin). Each old per-role case is one keyed row below, so a
// failure names the exact role that regressed.

describe('Escalated capability matrix', function () {
    test('the escalated-agent ability matches the matrix for every role', function () {
        $actual = [];
        foreach ($this->actors as $role => $user) {
            $actual[$role] = $user->can('escalated-agent');
        }

        expect($actual)->toBe(array_map(fn (array $row) => $row['agent'], escalatedVisibilityMatrix()));
    });

    test('the escalated-admin ability matches the matrix for every role', function () {
        $actual = [];
        foreach ($this->actors as $role => $user) {
            $actual[$role] = $user->can('escalated-admin');
        }

        expect($actual)->toBe(array_map(fn (array $row) => $row['admin'], escalatedVisibilityMatrix()));
    });
});

// ── Resource visibility gates ─────────────────────────────────────────────
//
// Replaces the old per-role 'Ticket visibility' block (6 cases), the 16
// dataset cases of 'Admin-only resource visibility', and the per-role
// resource rows of the old 'Visibility matrix' block (Department / Tag /
// SlaPolicy / Role / Webhook for Service, Platform and Games Admin).

describe('Resource visibility gates', function () {
    test('Ticket visibility follows the escalated-agent gate for every role', function () {
        $actual = [];
        foreach ($this->actors as $role => $user) {
            $actual[$role] = Gate::forUser($user)->allows('viewAny', Ticket::class);
        }

        expect($actual)->toBe(array_map(fn (array $row) => $row['ticket'], escalatedVisibilityMatrix()));
    });

    test('every admin-only resource follows the escalated-admin gate for every role', function () {
        $expected = [];
        $actual = [];

        foreach (escalatedAdminResources() as $label => $model) {
            foreach ($this->actors as $role => $user) {
                $expected[$label.' / '.$role] = escalatedVisibilityMatrix()[$role]['admin'];
                $actual[$label.' / '.$role] = Gate::forUser($user)->allows('viewAny', $model);
            }
        }

        expect($actual)->toBe($expected);
    });
});

<?php

use App\Enums\BadgeEventAction;
use App\Enums\BadgeSource;
use App\Models\BadgeEvent;
use App\Models\BadgeUser;
use App\Models\DiscordGuild;
use App\Models\DiscordGuildOrganizer;
use App\Models\User;
use App\Services\Badges\BadgeService;
use App\Services\Badges\GuildLinkedEvaluator;
use Carbon\Carbon;

/**
 * GuildLinkedEvaluator (M069 S05 T2, spec §3 Community Infrastructure
 * row, D209): the organizer's Discord guild link has aged thirty days
 * and is still alive — the guild row exists and the landlord has not
 * paused it.
 *
 * OneTime, tierless, no lapse (D209): every proposal carries a null
 * tier and points at the earliest qualifying DiscordGuildOrganizer
 * opt-in row (morph alias 'discord_guild_organizer' per D170), and a
 * null proposal never parks a hold. Write-side guarantees (audited
 * rows, idempotent re-runs) ride the S02 BadgeService engine — these
 * tests pin the D209 derivation, its 30-day boundary, and that the
 * sweep routes through it.
 */

/**
 * One organizer opt-in row for the user, aged as given (null = opted
 * out) on the given or a fresh live guild.
 */
function guildLinkedOptIn(User $user, ?Carbon $optedInAt, ?DiscordGuild $guild = null): DiscordGuildOrganizer
{
    return DiscordGuildOrganizer::factory()
        ->for($guild ?? DiscordGuild::factory(), 'guild')
        ->for($user, 'user')
        ->create([
            'publish_enabled' => $optedInAt !== null,
            'opted_in_at' => $optedInAt,
        ]);
}

/**
 * The user's guild_linked hold row, or null when not held.
 */
function guildLinkedHold(): ?BadgeUser
{
    return BadgeUser::query()
        ->whereHas('badgeDefinition', fn ($query) => $query->where('key', 'guild_linked'))
        ->first();
}

beforeEach(function () {
    $this->artisan('badges:sync')->assertSuccessful();
});

it('proposes the badge once the guild link has aged thirty days', function () {
    $user = User::factory()->create();
    $optIn = guildLinkedOptIn($user, now()->subDays(31));

    $award = app(GuildLinkedEvaluator::class)->evaluate($user);

    expect($award)->not->toBeNull()
        ->and($award->badgeKey)->toBe('guild_linked')
        // Tierless OneTime: the link either aged or it did not.
        ->and($award->tierKey)->toBeNull()
        // Evidence via the D170 morph alias, never the FQCN.
        ->and($award->evidenceType)->toBe('discord_guild_organizer')
        ->and($award->evidenceId)->toBe((string) $optIn->id);
});

it('counts the thirtieth day itself', function () {
    $user = User::factory()->create();
    guildLinkedOptIn($user, now()->subDays(30));

    // D209's floor is inclusive: opted_in_at <= now − 30 days.
    expect(app(GuildLinkedEvaluator::class)->evaluate($user))->not->toBeNull();
});

it('proposes nothing while the link is under thirty days old', function () {
    $user = User::factory()->create();
    guildLinkedOptIn($user, now()->subDays(29));

    expect(app(GuildLinkedEvaluator::class)->evaluate($user))->toBeNull();
});

it('never proposes without an opt-in', function () {
    $user = User::factory()->create();
    guildLinkedOptIn($user, null);

    // publish_enabled false and no opted_in_at: no consent, no badge.
    expect(app(GuildLinkedEvaluator::class)->evaluate($user))->toBeNull();
});

it('never proposes while the guild is paused', function () {
    $user = User::factory()->create();
    guildLinkedOptIn($user, now()->subDays(90), DiscordGuild::factory()->paused()->create());

    // A paused guild is the D209 "not still connected" signal: the
    // landlord switched posting off.
    expect(app(GuildLinkedEvaluator::class)->evaluate($user))->toBeNull();
});

it('loses qualification when the guild disappears', function () {
    $user = User::factory()->create();
    $guild = DiscordGuild::factory()->create();
    guildLinkedOptIn($user, now()->subDays(90), $guild);

    expect(app(GuildLinkedEvaluator::class)->evaluate($user))->not->toBeNull();

    // The organizers FK cascades on guild delete, so a dangling
    // guild_id is impossible — guild deletion ending the qualification
    // IS the production "missing guild row" path.
    $guild->delete();

    expect(app(GuildLinkedEvaluator::class)->evaluate($user))->toBeNull();
});

it('points at the earliest qualifying link when several exist', function () {
    $user = User::factory()->create();
    $earliest = guildLinkedOptIn($user, now()->subDays(90));
    guildLinkedOptIn($user, now()->subDays(31));

    $award = app(GuildLinkedEvaluator::class)->evaluate($user);

    // Ordered by opted_in_at: repeat sweeps always re-propose the
    // same evidence pointer.
    expect($award?->evidenceId)->toBe((string) $earliest->id);
});

it('never proposes for someone else\'s link', function () {
    $organizer = User::factory()->create();
    guildLinkedOptIn($organizer, now()->subDays(90));

    expect(app(GuildLinkedEvaluator::class)->evaluate(User::factory()->create()))->toBeNull();
});

it('is a pure proposal — evaluate never writes badge state', function () {
    $user = User::factory()->create();
    guildLinkedOptIn($user, now()->subDays(90));

    app(GuildLinkedEvaluator::class)->evaluate($user);

    expect(BadgeUser::query()->count())->toBe(0)
        ->and(BadgeEvent::query()->count())->toBe(0);
});

it('backfills the badge from guild links with no prior state', function () {
    $user = User::factory()->create();
    $optIn = guildLinkedOptIn($user, now()->subDays(45));

    app(GuildLinkedEvaluator::class)->sweep();

    $hold = guildLinkedHold();
    $audit = BadgeEvent::query()->first();

    // The backfill demo in miniature: a 45-day-old link, zero badge
    // state, one sweep → guild_linked in place with a full audit row.
    expect($hold)->not->toBeNull()
        ->and($hold->tier)->toBeNull()
        ->and($hold->evidence_type)->toBe('discord_guild_organizer')
        ->and($hold->evidence_id)->toBe((string) $optIn->id)
        ->and($audit?->action)->toBe(BadgeEventAction::Awarded)
        ->and(BadgeUser::query()->count())->toBe(1)
        ->and(BadgeEvent::query()->count())->toBe(1);
});

it('awards each user once — across links and repeat sweeps', function () {
    $user = User::factory()->create();
    guildLinkedOptIn($user, now()->subDays(90));
    guildLinkedOptIn($user, now()->subDays(31));

    app(GuildLinkedEvaluator::class)->sweep();
    app(GuildLinkedEvaluator::class)->sweep();

    // Several qualifying links and a repeat sweep still collapse into
    // exactly one hold and one audit row — BadgeService's AlreadyHeld
    // decision keeps the one-per-user promise.
    expect(BadgeUser::query()->count())->toBe(1)
        ->and(BadgeEvent::query()->count())->toBe(1);
});

it('one-time badges never lapse — a holder without links is untouched', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();

    app(BadgeService::class)->award(
        $user,
        'guild_linked',
        null,
        null,
        BadgeSource::Manual,
        $admin,
        'Grandfathered before the badge engine shipped',
    );

    // The holder has no organizer rows at all: the cohort's holder
    // arm pulls them in, but a null proposal must park nothing — the
    // manual award's audit row stays the only one.
    app(GuildLinkedEvaluator::class)->sweep();

    $hold = guildLinkedHold();

    expect($hold)->not->toBeNull()
        ->and($hold->lapsed_at)->toBeNull()
        ->and($hold->revoked_at)->toBeNull()
        ->and(BadgeEvent::query()->count())->toBe(1);
});

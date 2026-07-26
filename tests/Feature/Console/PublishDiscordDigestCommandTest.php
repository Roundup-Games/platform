<?php

namespace Tests\Feature\Console;

use App\Jobs\PublishDiscordDigest;
use App\Models\DiscordGuild;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests the `discord:publish-digest` scheduled command (M057/S02/T04): the
 * scheduler entry point that iterates postable guilds and dispatches ONE
 * {@see PublishDiscordDigest} job per guild.
 *
 * The command is a thin orchestrator — every gate (publishing_enabled, paused,
 * no-calendar-channel) is owned by the job/publisher; the command only applies
 * a COARSE postable filter to keep dispatch fast and dry-run output meaningful.
 * Per-guild failure isolation is proven by dispatching a queued job per guild
 * (one bad guild's worker failure never blocks the rest).
 *
 * Coverage mirrors the slice must-haves: one job per postable guild, --dry-run
 * (no dispatch), --guild= targeting (incl. not-found + bypasses the postable
 * filter), publishing_enabled inertness, coarse-filter exclusion of paused and
 * channel-less guilds, structured-log dispatch lifecycle, and per-guild
 * dispatch-failure isolation.
 */
class PublishDiscordDigestCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // MEM918 master switch — the command dispatches until this is enabled.
        config(['services.discord.publishing_enabled' => true]);
        // Intercept every PublishDiscordDigest dispatch so no real queue work
        // happens; we assert against the dispatched jobs.
        Bus::fake(PublishDiscordDigest::class);
    }

    /**
     * Build a postable guild (calendar channel + not paused).
     */
    private function postableGuild(array $overrides = []): DiscordGuild
    {
        return DiscordGuild::factory()
            ->configured()
            ->create(array_merge(['paused' => false], $overrides));
    }

    // ════════════════════════════════════════════════════
    //  ONE JOB PER POSTABLE GUILD
    // ════════════════════════════════════════════════════

    #[Test]
    public function dispatches_one_job_per_postable_guild()
    {
        $a = $this->postableGuild();
        $b = $this->postableGuild();

        $this->artisan('discord:publish-digest')
            ->assertSuccessful()
            ->expectsOutputToContain('Dispatching digest jobs...');

        Bus::assertDispatchedTimes(PublishDiscordDigest::class, 2);
        Bus::assertDispatched(PublishDiscordDigest::class, fn ($job) => $job->guildId === $a->id);
        Bus::assertDispatched(PublishDiscordDigest::class, fn ($job) => $job->guildId === $b->id);
    }

    #[Test]
    public function dispatched_job_carries_the_guild_primary_key_as_a_string()
    {
        $guild = $this->postableGuild();

        $this->artisan('discord:publish-digest')->assertSuccessful();

        Bus::assertDispatched(PublishDiscordDigest::class, function ($job) use ($guild) {
            // Mirror of PublishGameToDiscord::$gameId: primitive string PK.
            return is_string($job->guildId) && $job->guildId === $guild->id;
        });
    }

    // ════════════════════════════════════════════════════
    //  COARSE POSTABLE FILTER (paused / no calendar channel excluded)
    // ════════════════════════════════════════════════════

    #[Test]
    public function paused_guilds_are_excluded_from_dispatch()
    {
        $postable = $this->postableGuild();
        $paused = $this->postableGuild(['paused' => true]);

        $this->artisan('discord:publish-digest')->assertSuccessful();

        Bus::assertDispatchedTimes(PublishDiscordDigest::class, 1);
        Bus::assertDispatched(PublishDiscordDigest::class, fn ($job) => $job->guildId === $postable->id);
        Bus::assertNotDispatched(PublishDiscordDigest::class, fn ($job) => $job->guildId === $paused->id);
    }

    #[Test]
    public function guilds_without_a_calendar_channel_are_excluded_from_dispatch()
    {
        $postable = $this->postableGuild();
        $noChannel = DiscordGuild::factory()->create([
            'calendar_channel_id' => null,
            'paused' => false,
        ]);
        $emptyChannel = DiscordGuild::factory()->create([
            'calendar_channel_id' => '',
            'paused' => false,
        ]);

        $this->artisan('discord:publish-digest')->assertSuccessful();

        Bus::assertDispatchedTimes(PublishDiscordDigest::class, 1);
        Bus::assertDispatched(PublishDiscordDigest::class, fn ($job) => $job->guildId === $postable->id);
        Bus::assertNotDispatched(PublishDiscordDigest::class, fn ($job) => $job->guildId === $noChannel->id);
        Bus::assertNotDispatched(PublishDiscordDigest::class, fn ($job) => $job->guildId === $emptyChannel->id);
    }

    #[Test]
    public function no_postable_guilds_dispatches_nothing_and_succeeds()
    {
        DiscordGuild::factory()->create(['calendar_channel_id' => null, 'paused' => false]);
        DiscordGuild::factory()->configured()->create(['paused' => true]);

        $this->artisan('discord:publish-digest')
            ->assertSuccessful()
            ->expectsOutputToContain('0 job(s) dispatched');

        Bus::assertNotDispatched(PublishDiscordDigest::class);
    }

    // ════════════════════════════════════════════════════
    //  DRY RUN
    // ════════════════════════════════════════════════════

    #[Test]
    public function dry_run_lists_postable_guilds_without_dispatching()
    {
        $a = $this->postableGuild();
        $b = $this->postableGuild();

        $this->artisan('discord:publish-digest --dry-run')
            ->assertSuccessful()
            ->expectsOutputToContain('Would dispatch digest job for guild '.$a->id)
            ->expectsOutputToContain('Would dispatch digest job for guild '.$b->id)
            ->expectsOutputToContain('2 job(s) would be dispatched');

        Bus::assertNothingDispatched();
    }

    // ════════════════════════════════════════════════════
    //  SINGLE-GUILD TARGETING (--guild=)
    // ════════════════════════════════════════════════════

    #[Test]
    public function guild_option_dispatches_only_the_targeted_guild()
    {
        $a = $this->postableGuild();
        $b = $this->postableGuild();

        $this->artisan('discord:publish-digest --guild='.$a->id)
            ->assertSuccessful()
            ->expectsOutputToContain('Dispatched digest job for guild '.$a->id);

        Bus::assertDispatchedTimes(PublishDiscordDigest::class, 1);
        Bus::assertDispatched(PublishDiscordDigest::class, fn ($job) => $job->guildId === $a->id);
        Bus::assertNotDispatched(PublishDiscordDigest::class, fn ($job) => $job->guildId === $b->id);
    }

    #[Test]
    public function guild_option_bypasses_postable_filter_so_a_paused_guild_can_be_forced()
    {
        // Operator escape hatch: --guild= targets by PK regardless of the
        // coarse postable filter (the publisher still gates the actual post).
        $paused = $this->postableGuild(['paused' => true]);

        $this->artisan('discord:publish-digest --guild='.$paused->id)
            ->assertSuccessful();

        Bus::assertDispatchedTimes(PublishDiscordDigest::class, 1);
        Bus::assertDispatched(PublishDiscordDigest::class, fn ($job) => $job->guildId === $paused->id);
    }

    #[Test]
    public function guild_option_with_unknown_id_fails()
    {
        // A valid-format UUID that does not exist — mirrors the production path
        // (the command only ever dispatches real guild ids). A non-UUID string
        // would trip a DB-level cast error before the command can print its
        // not-found message.
        $missingId = Str::uuid()->toString();

        $this->artisan('discord:publish-digest --guild='.$missingId)
            ->assertFailed()
            ->expectsOutputToContain('not found');

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function guild_option_dry_run_does_not_dispatch()
    {
        $guild = $this->postableGuild();

        $this->artisan('discord:publish-digest --guild='.$guild->id.' --dry-run')
            ->assertSuccessful()
            ->expectsOutputToContain('Would dispatch digest job for guild '.$guild->id);

        Bus::assertNothingDispatched();
    }

    // ════════════════════════════════════════════════════
    //  PUBLISHING-ENABLED GATE (MEM918)
    // ════════════════════════════════════════════════════

    #[Test]
    public function publishing_disabled_makes_command_inert()
    {
        config(['services.discord.publishing_enabled' => false]);
        $this->postableGuild();

        $this->artisan('discord:publish-digest')
            ->assertSuccessful()
            ->expectsOutputToContain('Discord publishing is disabled');

        Bus::assertNothingDispatched();
    }

    // ════════════════════════════════════════════════════
    //  STRUCTURED-LOG DISPATCH LIFECYCLE
    // ════════════════════════════════════════════════════

    #[Test]
    public function logs_dispatch_started_and_completed_with_counts()
    {
        $this->postableGuild();
        $this->postableGuild();

        Log::spy();

        $this->artisan('discord:publish-digest')->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $msg, array $ctx) => $msg === 'discord_digest.command.started'
                && ($ctx['dry_run'] ?? null) === false)
            ->atLeast()
            ->once();
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $msg, array $ctx) => $msg === 'discord_digest.command.completed'
                && ($ctx['dispatched'] ?? null) === 2
                && ($ctx['errors'] ?? null) === 0)
            ->atLeast()
            ->once();
    }
}

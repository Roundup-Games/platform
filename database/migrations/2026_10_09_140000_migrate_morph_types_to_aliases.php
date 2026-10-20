<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Convert every stored morph FQCN to its enforced alias (D170).
 *
 * Relation::enforceMorphMap (AppServiceProvider) makes morphTo READS
 * tolerant of both forms (alias resolves through the map; FQCN falls
 * back to the class itself). Raw where(..._type) queries are NOT
 * tolerant: role checks (model_has_roles), short-link/token lookups and
 * ticket-requester queries match exactly one form. This migration and
 * the code that writes/queries aliases MUST therefore ship as one
 * atomic release with migrate at cutover — a rolling window where old
 * servers write FQCNs (or new code queries aliases against FQCN rows)
 * silently splits the data this migration exists to cure.
 *
 * The alias list below is FROZEN at authorship time — migrations must not
 * read runtime state (a later map edit must not change what this
 * migration does on a fresh run). It mirrors the D170 map exactly;
 * tests/Feature/Platform/UuidPolicyTest.php pins that parity.
 *
 * Discovers every morph pair (<name>_type + <name>_id) in the public
 * schema, converts known FQCNs, and logs any *_type value that is neither
 * an alias nor a mapped FQCN (e.g. vendor-internal escalated classes —
 * those are left untouched on purpose).
 */
return new class extends Migration
{
    /** @var array<string, string> alias => FQCN, frozen copy of the D170 map */
    private const ALIASES = [
        'campaign' => 'App\\Models\\Campaign',
        // The M069 badge-evidence aliases (discord_guild_organizer,
        // local_subscription, subscription) and the M070/S04 chartered
        // evidence alias (session_zero_confirmation) joined the enforced
        // map after this migration first ran and are appended here in
        // lockstep (UuidPolicyTest pins frozen ~= live). None of these
        // classes ever stored FQCN rows -- their writes went through
        // getMorphClass() from day one -- so up()/down() convert nothing
        // for them on fresh or re-runs; the frozen behavior is unchanged.
        'discord_guild_organizer' => 'App\\Models\\DiscordGuildOrganizer',
        'event' => 'App\\Models\\Event',
        'event_announcement' => 'App\\Models\\EventAnnouncement',
        'game' => 'App\\Models\\Game',
        'game_participant' => 'App\\Models\\GameParticipant',
        'game_system' => 'App\\Models\\GameSystem',
        'local_subscription' => 'App\\Models\\LocalSubscription',
        'location' => 'App\\Models\\Location',
        'review' => 'App\\Models\\Review',
        'session_zero_confirmation' => 'App\\Models\\SessionZeroConfirmation',
        'subscription' => 'Laravel\\Paddle\\Subscription',
        'team' => 'App\\Models\\Team',
        'user' => 'App\\Models\\User',
        'user_relationship' => 'App\\Models\\UserRelationship',
    ];

    /**
     * Business-discriminator *_type columns whose *_id sibling exists by
     * coincidence of naming — they are NOT morph columns. Shared by
     * up() AND down(): up() must never rewrite them, and down()
     * rewriting them to FQCNs would silently corrupt business data the
     * forward direction deliberately left untouched.
     */
    private const EXCLUDED_TYPE_COLUMNS = [
        'event_type', 'game_type', 'venue_type', 'ticket_type',
        'agent_type', 'preference_type', 'tool_type', 'bgg_type', 'join_source',
    ];

    public function up(): void
    {
        foreach ($this->morphTables() as $table) {
            $typeColumns = $this->morphTypeColumns($table);

            foreach ($typeColumns as $typeColumn) {
                $converted = 0;

                foreach (self::ALIASES as $alias => $fqcn) {
                    $converted += DB::table($table)
                        ->where($typeColumn, $fqcn)
                        ->update([$typeColumn => $alias]);
                }

                if ($converted > 0) {
                    Log::info('morph aliases migrated', [
                        'table' => $table,
                        'column' => $typeColumn,
                        'converted' => $converted,
                    ]);
                }

                $unmapped = DB::table($table)
                    ->whereNotNull($typeColumn)
                    ->whereNotIn($typeColumn, array_keys(self::ALIASES))
                    ->whereNotIn($typeColumn, array_values(self::ALIASES))
                    ->distinct()
                    ->pluck($typeColumn);

                if ($unmapped->isNotEmpty()) {
                    Log::warning('morph type values left as-is (not in D170 map)', [
                        'table' => $table,
                        'column' => $typeColumn,
                        'values' => $unmapped->take(20)->all(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Reverse: alias -> FQCN. Same discovery as up() — including the
        // business-discriminator exclusions, so rollback is symmetric
        // with the forward direction and never rewrites columns up()
        // deliberately left untouched.
        foreach ($this->morphTables() as $table) {
            $typeColumns = $this->morphTypeColumns($table);

            foreach ($typeColumns as $typeColumn) {
                foreach (self::ALIASES as $alias => $fqcn) {
                    DB::table($table)
                        ->where($typeColumn, $alias)
                        ->update([$typeColumn => $fqcn]);
                }
            }
        }
    }

    /**
     * Every table carrying at least one *_type column in the public
     * schema (code-controlled discovery — never user input).
     *
     * @return list<string>
     */
    private function morphTables(): array
    {
        $tables = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('column_name', 'like', '%\_type')
            ->pluck('table_name');

        return array_values(array_unique($tables->all()));
    }

    /**
     * The morph *_type columns on one table: *_id sibling present and
     * not a known business discriminator.
     *
     * @return list<string>
     */
    private function morphTypeColumns(string $table): array
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', $table)
            ->where('column_name', 'like', '%\_type')
            ->pluck('column_name')
            ->filter(function (string $column) use ($table): bool {
                $idColumn = str_replace('_type', '_id', $column);

                $idExists = DB::table('information_schema.columns')
                    ->where('table_schema', 'public')
                    ->where('table_name', $table)
                    ->where('column_name', $idColumn)
                    ->exists();

                return $idExists && ! in_array($column, self::EXCLUDED_TYPE_COLUMNS, true);
            });

        return array_values($columns->all());
    }
};

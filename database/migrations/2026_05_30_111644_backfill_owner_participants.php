<?php

use App\Enums\AttendanceStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Backfills owner participants for all existing games and campaigns.
     * For each game/campaign, if no participant record exists with the
     * owner's user_id, one is created with role='owner' and status='approved'.
     * If an existing record has a non-owner role, it is upgraded to 'owner'.
     *
     * For completed games, the owner's attendance_status is set to 'attended'.
     */
    public function up(): void
    {
        $this->backfillGameOwners();
        $this->backfillCampaignOwners();
    }

    /**
     * Backfill owner participants for games.
     *
     * Uses chunked processing (500 at a time). For each game:
     * - Creates an owner participant if none exists.
     * - Upgrades role to 'owner' if a participant exists with a different role.
     * - Sets attendance_status='attended' for completed games (only if currently null).
     *
     * Observers are not explicitly disabled, but writes use DB::table()
     * which bypasses Eloquent events (including observers). Read queries
     * via Eloquent (GameParticipant::where) do trigger model loading but
     * perform no writes, so observer side-effects are not a concern.
     */
    private function backfillGameOwners(): void
    {
        $total = 0;

        Game::query()
            ->select(['id', 'owner_id', 'status', 'created_at', 'updated_at'])
            ->whereNotNull('owner_id')
            ->chunkById(500, function ($games) use (&$total) {
                foreach ($games as $game) {
                    $isCompleted = $game->status === GameStatus::Completed;

                    $existing = GameParticipant::where('game_id', $game->id)
                        ->where('user_id', $game->owner_id)
                        ->first();

                    if ($existing) {
                        $updates = [];
                        // Upgrade role if a pre-existing record had a different role
                        if ($existing->role !== ParticipantRole::Owner) {
                            $updates['role'] = ParticipantRole::Owner->value;
                        }
                        // Backfill attendance for completed games regardless of current role
                        if ($isCompleted && $existing->attendance_status === null) {
                            $updates['attendance_status'] = AttendanceStatus::Attended->value;
                            $updates['attendance_reported_at'] = $game->updated_at;
                        }
                        if ($updates !== []) {
                            DB::table('game_participants')->where('id', $existing->id)->update($updates);
                        }
                    } else {
                        DB::table('game_participants')->insert([
                            'id' => (string) Str::uuid(),
                            'game_id' => $game->id,
                            'user_id' => $game->owner_id,
                            'role' => ParticipantRole::Owner->value,
                            'status' => ParticipantStatus::Approved->value,
                            'created_at' => $game->created_at,
                            'attendance_status' => $isCompleted
                                ? AttendanceStatus::Attended->value
                                : null,
                            'attendance_reported_at' => $isCompleted
                                ? $game->updated_at
                                : null,
                        ]);
                    }

                    $total++;
                }

                $this->info("Processed {$total} games so far...");
            });

        $this->info("Backfilled owner participants for {$total} games.");
    }

    /**
     * Backfill owner participants for campaigns.
     *
     * Uses chunked processing (500 at a time). For each campaign:
     * - Creates an owner participant if none exists.
     * - Upgrades role to 'owner' if a participant exists with a different role.
     *
     * Observers are not explicitly disabled, but writes use DB::table()
     * which bypasses Eloquent events (including observers). Read queries
     * via Eloquent (CampaignParticipant::where) do trigger model loading
     * but perform no writes, so observer side-effects are not a concern.
     */
    private function backfillCampaignOwners(): void
    {
        $total = 0;

        Campaign::query()
            ->select(['id', 'owner_id', 'created_at'])
            ->whereNotNull('owner_id')
            ->chunkById(500, function ($campaigns) use (&$total) {
                foreach ($campaigns as $campaign) {
                    $existing = CampaignParticipant::where('campaign_id', $campaign->id)
                        ->where('user_id', $campaign->owner_id)
                        ->first();

                    if ($existing) {
                        if ($existing->role !== ParticipantRole::Owner) {
                            DB::table('campaign_participants')->where('id', $existing->id)
                                ->update(['role' => ParticipantRole::Owner->value]);
                        }
                    } else {
                        DB::table('campaign_participants')->insert([
                            'id' => (string) Str::uuid(),
                            'campaign_id' => $campaign->id,
                            'user_id' => $campaign->owner_id,
                            'role' => ParticipantRole::Owner->value,
                            'status' => ParticipantStatus::Approved->value,
                            'created_at' => $campaign->created_at,
                        ]);
                    }

                    $total++;
                }

                $this->info("Processed {$total} campaigns so far...");
            });

        $this->info("Backfilled owner participants for {$total} campaigns.");
    }

    /**
     * Reverse the migrations.
     *
     * This is a no-op by design. Rolling back a data backfill that creates
     * owner participant records is dangerous in production — genuinely new
     * owner participants (created by the app after this migration ran) would
     * also be at risk. Instead, rely on the idempotent up() and re-run
     * migration if needed.
     */
    public function down(): void
    {
        $this->info(
            'Rollback for backfill_owner_participants is a no-op. ' .
            'The up() migration is idempotent — re-run it to ensure all owners have participant records.'
        );
    }

    /**
     * Log an info message during migration execution.
     */
    private function info(string $message): void
    {
        if (app()->runningInConsole()) {
            echo $message . PHP_EOL;
        }
    }
};

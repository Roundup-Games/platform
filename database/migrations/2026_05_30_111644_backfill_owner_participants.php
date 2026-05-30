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
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Backfills owner participants for all existing games and campaigns.
     * For each game/campaign, if no participant record exists with the
     * owner's user_id, one is created with role='owner' and status='approved'.
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
     * Uses chunked processing (500 at a time) with firstOrCreate for idempotency.
     */
    private function backfillGameOwners(): void
    {
        $total = 0;

        Game::query()
            ->select(['id', 'owner_id', 'status', 'created_at', 'updated_at'])
            ->chunkById(500, function ($games) use (&$total) {
                foreach ($games as $game) {
                    $isCompleted = $game->status === GameStatus::Completed;

                    GameParticipant::firstOrCreate(
                        [
                            'game_id' => $game->id,
                            'user_id' => $game->owner_id,
                        ],
                        [
                            'id' => (string) Str::uuid(),
                            'role' => ParticipantRole::Owner->value,
                            'status' => ParticipantStatus::Approved->value,
                            'created_at' => $game->created_at,
                            'attendance_status' => $isCompleted
                                ? AttendanceStatus::Attended->value
                                : null,
                            'attendance_reported_at' => $isCompleted
                                ? $game->updated_at
                                : null,
                        ],
                    );

                    $total++;
                }

                $this->info("Processed {$total} games so far...");
            });

        $this->info("Backfilled owner participants for {$total} games.");
    }

    /**
     * Backfill owner participants for campaigns.
     *
     * Uses chunked processing (500 at a time) with firstOrCreate for idempotency.
     */
    private function backfillCampaignOwners(): void
    {
        $total = 0;

        Campaign::query()
            ->select(['id', 'owner_id', 'created_at'])
            ->chunkById(500, function ($campaigns) use (&$total) {
                foreach ($campaigns as $campaign) {
                    CampaignParticipant::firstOrCreate(
                        [
                            'campaign_id' => $campaign->id,
                            'user_id' => $campaign->owner_id,
                        ],
                        [
                            'id' => (string) Str::uuid(),
                            'role' => ParticipantRole::Owner->value,
                            'status' => ParticipantStatus::Approved->value,
                            'created_at' => $campaign->created_at,
                        ],
                    );

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
        // Migrations don't have a $command property; use logger or stdout directly
        if (app()->runningInConsole()) {
            echo $message . PHP_EOL;
        }
    }
};

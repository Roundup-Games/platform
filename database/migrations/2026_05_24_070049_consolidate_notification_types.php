<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Consolidate entity-specific notification classes into unified classes.
 *
 * Before this migration the notifications table stores per-entity PHP class names:
 *   App\Notifications\GameCancelled, App\Notifications\CampaignCancelled, …
 *
 * After this migration all eight legacy class names are rewritten to four unified:
 *   App\Notifications\EntityCancelled, EntityCompleted, EntityInvitation, EntityUpdated
 *
 * The notification data payload is unchanged — it already carries an entity-scoped
 * `type` field (e.g. `game_invitation`, `campaign_cancelled`) so the front-end and
 * NotificationQueryService need no adjustments.
 *
 * The down() method reverses the mapping by inspecting data->>'type' to determine
 * whether a record belongs to Game or Campaign, then restores the old class name.
 */
return new class extends Migration
{
    /**
     * Legacy class → unified class mapping for up().
     */
    private const CLASS_MAP = [
        'App\Notifications\GameCancelled' => 'App\Notifications\EntityCancelled',
        'App\Notifications\CampaignCancelled' => 'App\Notifications\EntityCancelled',
        'App\Notifications\GameCompleted' => 'App\Notifications\EntityCompleted',
        'App\Notifications\CampaignCompleted' => 'App\Notifications\EntityCompleted',
        'App\Notifications\GameInvitation' => 'App\Notifications\EntityInvitation',
        'App\Notifications\CampaignInvitation' => 'App\Notifications\EntityInvitation',
        'App\Notifications\GameUpdated' => 'App\Notifications\EntityUpdated',
        'App\Notifications\CampaignUpdated' => 'App\Notifications\EntityUpdated',
    ];

    /**
     * Unified class → (data.type prefix → legacy class) mapping for down().
     */
    private const REVERSE_MAP = [
        'App\Notifications\EntityCancelled' => [
            'game_' => 'App\Notifications\GameCancelled',
            'campaign_' => 'App\Notifications\CampaignCancelled',
        ],
        'App\Notifications\EntityCompleted' => [
            'game_' => 'App\Notifications\GameCompleted',
            'campaign_' => 'App\Notifications\CampaignCompleted',
        ],
        'App\Notifications\EntityInvitation' => [
            'game_' => 'App\Notifications\GameInvitation',
            'campaign_' => 'App\Notifications\CampaignInvitation',
        ],
        'App\Notifications\EntityUpdated' => [
            'game_' => 'App\Notifications\GameUpdated',
            'campaign_' => 'App\Notifications\CampaignUpdated',
        ],
    ];

    public function up(): void
    {
        foreach (self::CLASS_MAP as $old => $new) {
            DB::table('notifications')
                ->where('type', $old)
                ->update(['type' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::REVERSE_MAP as $unified => $mapping) {
            DB::table('notifications')
                ->where('type', $unified)
                ->chunkById(500, function ($notifications) use ($mapping) {
                    foreach ($notifications as $notification) {
                        $data = json_decode($notification->data, true);
                        $dataType = $data['type'] ?? null;

                        if ($dataType === null) {
                            continue;
                        }

                        foreach ($mapping as $prefix => $legacyClass) {
                            if (str_starts_with($dataType, $prefix)) {
                                DB::table('notifications')
                                    ->where('id', $notification->id)
                                    ->update(['type' => $legacyClass]);

                                break;
                            }
                        }
                    }
                });
        }
    }
};

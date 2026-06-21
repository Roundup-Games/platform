<?php

namespace Tests\Feature\Services;

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\JoinSource;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\ShortLink;
use App\Models\User;
use App\Services\ShareIntentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

describe('ShareIntentService', function () {
    beforeEach(function () {
        $this->service = new ShareIntentService;
        $this->owner = User::factory()->create();
        $this->user = User::factory()->create();
        $this->system = GameSystem::factory()->create();
    });

    // ── parsePayload ───────────────────────────────────

    describe('parsePayload', function () {
        it('parses valid array payload with UUID-shaped values', function () {
            $payload = [
                'entity_type' => 'game',
                'entity_id' => (string) Str::uuid(),
                'share_token' => (string) Str::uuid(),
            ];

            $result = $this->service->parsePayload($payload);

            expect($result)->toBe($payload);
        });

        it('parses valid JSON string payload', function () {
            $data = [
                'entity_type' => 'campaign',
                'entity_id' => (string) Str::uuid(),
                'share_token' => (string) Str::uuid(),
            ];

            $result = $this->service->parsePayload(json_encode($data));

            expect($result)->toBe($data);
        });

        it('returns null for missing fields', function () {
            expect($this->service->parsePayload(['entity_type' => 'game']))->toBeNull();
            expect($this->service->parsePayload([]))->toBeNull();
            expect($this->service->parsePayload(null))->toBeNull();
            expect($this->service->parsePayload(123))->toBeNull();
        });

        it('returns null for unsupported entity type', function () {
            $result = $this->service->parsePayload([
                'entity_type' => 'team',
                'entity_id' => (string) Str::uuid(),
                'share_token' => (string) Str::uuid(),
            ]);

            expect($result)->toBeNull();
        });
    });

    // ── parseShortLinkPayload ──────────────────────────

    describe('parseShortLinkPayload', function () {
        it('parses valid short_link_id from array', function () {
            $result = $this->service->parseShortLinkPayload(['short_link_id' => 42]);

            expect($result)->toBe(['short_link_id' => 42]);
        });

        it('parses JSON string', function () {
            $result = $this->service->parseShortLinkPayload(json_encode(['short_link_id' => 42]));

            expect($result)->toBe(['short_link_id' => 42]);
        });

        it('returns null for missing short_link_id', function () {
            expect($this->service->parseShortLinkPayload([]))->toBeNull();
            expect($this->service->parseShortLinkPayload('invalid'))->toBeNull();
        });
    });

    // ── processShareIntent — Game (entity-specific edge cases) ───────

    describe('processShareIntent — Game', function () {
        it('rejects mismatched share token', function () {
            $token = (string) Str::uuid();
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
                'share_token' => $token,
                'status' => GameStatus::Scheduled,
            ]);

            $result = $this->service->processShareIntent([
                'entity_type' => 'game',
                'entity_id' => $game->id,
                'share_token' => (string) Str::uuid(),
            ], $this->user);

            expect($result->shouldRedirect)->toBeFalse();
            expect(GameParticipant::where('game_id', $game->id)->count())->toBe(0);
        });

        it('rejects expired share token', function () {
            $token = (string) Str::uuid();
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
                'share_token' => $token,
                'share_token_expires_at' => now()->subDay(),
                'status' => GameStatus::Scheduled,
            ]);

            $result = $this->service->processShareIntent([
                'entity_type' => 'game',
                'entity_id' => $game->id,
                'share_token' => $token,
            ], $this->user);

            expect($result->shouldRedirect)->toBeFalse();
        });

        it('returns existing participant without duplication', function () {
            $token = (string) Str::uuid();
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
                'share_token' => $token,
                'status' => GameStatus::Scheduled,
            ]);
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->user->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
                'join_source' => JoinSource::Application,
            ]);

            $result = $this->service->processShareIntent([
                'entity_type' => 'game',
                'entity_id' => $game->id,
                'share_token' => $token,
            ], $this->user);

            expect($result->shouldRedirect)->toBeTrue();
            expect(GameParticipant::where('game_id', $game->id)
                ->where('user_id', $this->user->id)->count())->toBe(1);
        });

        it('returns gracefully for non-existent entity', function () {
            $result = $this->service->processShareIntent([
                'entity_type' => 'game',
                'entity_id' => (string) Str::uuid(),
                'share_token' => (string) Str::uuid(),
            ], $this->user);

            expect($result->shouldRedirect)->toBeFalse();
        });
    });

    // ── processShareIntent — shared contract (game + campaign) ────────

    describe('processShareIntent — shared (game + campaign)', function () {
        it('redirects owner without creating participant', function (string $entityType) {
            $token = (string) Str::uuid();

            $entity = match ($entityType) {
                'game' => Game::factory()->create([
                    'owner_id' => $this->owner->id,
                    'game_system_id' => $this->system->id,
                    'share_token' => $token,
                    'status' => GameStatus::Scheduled,
                ]),
                'campaign' => Campaign::factory()->create([
                    'owner_id' => $this->owner->id,
                    'game_system_id' => $this->system->id,
                    'share_token' => $token,
                    'status' => CampaignStatus::Active,
                ]),
            };

            $result = $this->service->processShareIntent([
                'entity_type' => $entityType,
                'entity_id' => $entity->id,
                'share_token' => $token,
            ], $this->owner);

            expect($result->shouldRedirect)->toBeTrue()
                ->and($result->redirectRoute)->toBe($entityType === 'game' ? 'games.show' : 'campaigns.show');

            $count = match ($entityType) {
                'game' => GameParticipant::where('game_id', $entity->id)->count(),
                'campaign' => CampaignParticipant::where('campaign_id', $entity->id)->count(),
            };
            expect($count)->toBe(0);
        })->with(['game', 'campaign']);

        it('creates participant with share_link join source for valid token', function (string $entityType) {
            $token = (string) Str::uuid();

            $entity = match ($entityType) {
                'game' => Game::factory()->create([
                    'owner_id' => $this->owner->id,
                    'game_system_id' => $this->system->id,
                    'share_token' => $token,
                    'status' => GameStatus::Scheduled,
                ]),
                'campaign' => Campaign::factory()->create([
                    'owner_id' => $this->owner->id,
                    'game_system_id' => $this->system->id,
                    'share_token' => $token,
                    'status' => CampaignStatus::Active,
                ]),
            };

            $result = $this->service->processShareIntent([
                'entity_type' => $entityType,
                'entity_id' => $entity->id,
                'share_token' => $token,
            ], $this->user);

            expect($result->shouldRedirect)->toBeTrue()
                ->and($result->redirectRoute)->toBe($entityType === 'game' ? 'games.show' : 'campaigns.show');

            $participant = match ($entityType) {
                'game' => GameParticipant::where('game_id', $entity->id)->where('user_id', $this->user->id)->first(),
                'campaign' => CampaignParticipant::where('campaign_id', $entity->id)->where('user_id', $this->user->id)->first(),
            };
            expect($participant)->not->toBeNull()
                ->and($participant->join_source)->toBe(JoinSource::ShareLink);

            // Game participants get the Player role assigned by the service.
            if ($entityType === 'game') {
                expect($participant->role)->toBe(ParticipantRole::Player);
            }
        })->with(['game', 'campaign']);
    });

    // ── processShareIntent — edge cases ────────────────

    describe('processShareIntent — edge cases', function () {
        it('returns gracefully for unsupported entity type', function () {
            $result = $this->service->processShareIntent([
                'entity_type' => 'team',
                'entity_id' => (string) Str::uuid(),
                'share_token' => (string) Str::uuid(),
            ], $this->user);

            expect($result->shouldRedirect)->toBeFalse();
        });

        it('puts overflowed game participant on waitlist', function () {
            $token = (string) Str::uuid();
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
                'share_token' => $token,
                'status' => GameStatus::Scheduled,
                'max_players' => 1,
            ]);
            // Fill to capacity
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => User::factory()->create()->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
                'join_source' => JoinSource::Application,
            ]);

            $result = $this->service->processShareIntent([
                'entity_type' => 'game',
                'entity_id' => $game->id,
                'share_token' => $token,
            ], $this->user);

            expect($result->shouldRedirect)->toBeTrue();
            $participant = GameParticipant::where('game_id', $game->id)
                ->where('user_id', $this->user->id)
                ->first();
            expect($participant)->not->toBeNull();
            expect($participant->status)->toBe(ParticipantStatus::Waitlisted);
        });
    });

    // ── processShortLinkIntent ─────────────────────────

    describe('processShortLinkIntent', function () {
        it('creates participant via game short link', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
                'status' => GameStatus::Scheduled,
            ]);
            $shortLink = ShortLink::factory()->create([
                'linkable_type' => Game::class,
                'linkable_id' => $game->id,
                'user_id' => $this->owner->id,
            ]);

            $result = $this->service->processShortLinkIntent($shortLink, $this->user);

            expect($result->shouldRedirect)->toBeTrue();
            $participant = GameParticipant::where('game_id', $game->id)
                ->where('user_id', $this->user->id)
                ->first();
            expect($participant)->not->toBeNull();
            expect($participant->join_source)->toBe(JoinSource::ShortLink);
        });

        it('creates participant via campaign short link', function () {
            $campaign = Campaign::factory()->create([
                'owner_id' => $this->owner->id,
                'game_system_id' => $this->system->id,
                'status' => CampaignStatus::Active,
            ]);
            $shortLink = ShortLink::factory()->create([
                'linkable_type' => Campaign::class,
                'linkable_id' => $campaign->id,
                'user_id' => $this->owner->id,
            ]);

            $result = $this->service->processShortLinkIntent($shortLink, $this->user);

            expect($result->shouldRedirect)->toBeTrue();
            expect($result->redirectRoute)->toBe('campaigns.show');
            $participant = CampaignParticipant::where('campaign_id', $campaign->id)
                ->where('user_id', $this->user->id)
                ->first();
            expect($participant)->not->toBeNull();
            expect($participant->join_source)->toBe(JoinSource::ShortLink);
        });

        it('returns gracefully for non-existent linkable entity', function () {
            $shortLink = ShortLink::factory()->create([
                'linkable_type' => Game::class,
                'linkable_id' => (string) Str::uuid(), // non-existent game
                'user_id' => $this->owner->id,
            ]);

            $result = $this->service->processShortLinkIntent($shortLink, $this->user);

            expect($result->shouldRedirect)->toBeFalse();
            expect($result->shouldClearCookie)->toBeTrue();
        });
    });
});

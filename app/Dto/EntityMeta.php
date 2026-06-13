<?php

namespace App\Dto;

use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;

/**
 * Describes the entity metadata for a participant's parent entity (Game or Campaign).
 *
 * Replaces the ad-hoc array{type, foreignKey, entityClass, participantClass} shape
 * previously returned by entityMeta() methods and consumed across 15+ files.
 */
class EntityMeta
{
    /**
     * @param  string  $type  'game' or 'campaign'
     * @param  string  $foreignKey  Column name on the participant table (e.g. 'game_id')
     * @param  class-string<Game|Campaign>  $entityClass  The Eloquent model class
     * @param  class-string<GameParticipant|CampaignParticipant>  $participantClass  The participant model class
     */
    public function __construct(
        public readonly string $type,
        public readonly string $foreignKey,
        public readonly string $entityClass,
        public readonly string $participantClass,
    ) {}

    public function isCampaign(): bool
    {
        return $this->type === 'campaign';
    }

    public static function forGame(): self
    {
        return new self(
            type: 'game',
            foreignKey: 'game_id',
            entityClass: Game::class,
            participantClass: GameParticipant::class,
        );
    }

    public static function forCampaign(): self
    {
        return new self(
            type: 'campaign',
            foreignKey: 'campaign_id',
            entityClass: Campaign::class,
            participantClass: CampaignParticipant::class,
        );
    }

    /**
     * Resolve from a Game or Campaign instance.
     */
    public static function fromEntity(Game|Campaign $entity): self
    {
        return $entity instanceof Campaign ? self::forCampaign() : self::forGame();
    }

    /**
     * Resolve from a participant model instance.
     */
    public static function fromParticipant(GameParticipant|CampaignParticipant $participant): self
    {
        return $participant instanceof CampaignParticipant ? self::forCampaign() : self::forGame();
    }

    /**
     * @return array{type: string, foreignKey: string, entityClass: string, participantClass: string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'foreignKey' => $this->foreignKey,
            'entityClass' => $this->entityClass,
            'participantClass' => $this->participantClass,
        ];
    }

    /** @param array{type: string, foreignKey: string, entityClass: class-string<Game|Campaign>, participantClass: class-string<GameParticipant|CampaignParticipant>} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'],
            foreignKey: $data['foreignKey'],
            entityClass: $data['entityClass'],
            participantClass: $data['participantClass'],
        );
    }
}

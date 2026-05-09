<?php

namespace App\Dto;

use Carbon\Carbon;

class FeedItem
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $entityType,
        public readonly string $entityId,
        public readonly string $entityName,
        public readonly ?string $userName,
        public readonly ?string $userId,
        public readonly Carbon $createdAt,
        public readonly ?string $gameSystemName = null,
        public readonly ?int $participantCount = null,
        public readonly ?int $maxPlayers = null,
        public readonly ?string $imageUrl = null,
    ) {}

    /**
     * Serialize to a cache-safe array (no Eloquent models).
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'entityType' => $this->entityType,
            'entityId' => $this->entityId,
            'entityName' => $this->entityName,
            'userName' => $this->userName,
            'userId' => $this->userId,
            'createdAt' => $this->createdAt->toIso8601String(),
            'gameSystemName' => $this->gameSystemName,
            'participantCount' => $this->participantCount,
            'maxPlayers' => $this->maxPlayers,
            'imageUrl' => $this->imageUrl,
        ];
    }

    /**
     * Reconstruct from a cached array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            type: $data['type'],
            entityType: $data['entityType'],
            entityId: $data['entityId'],
            entityName: $data['entityName'],
            userName: $data['userName'] ?? null,
            userId: $data['userId'] ?? null,
            createdAt: Carbon::parse($data['createdAt']),
            gameSystemName: $data['gameSystemName'] ?? null,
            participantCount: $data['participantCount'] ?? null,
            maxPlayers: $data['maxPlayers'] ?? null,
            imageUrl: $data['imageUrl'] ?? null,
        );
    }
}

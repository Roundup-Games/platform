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
     *
     * @return array<string, mixed>
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
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: is_string($data['id'] ?? null) ? $data['id'] : '',
            type: is_string($data['type'] ?? null) ? $data['type'] : '',
            entityType: is_string($data['entityType'] ?? null) ? $data['entityType'] : '',
            entityId: is_string($data['entityId'] ?? null) ? $data['entityId'] : '',
            entityName: is_string($data['entityName'] ?? null) ? $data['entityName'] : '',
            userName: is_string($data['userName'] ?? null) ? $data['userName'] : null,
            userId: is_string($data['userId'] ?? null) ? $data['userId'] : null,
            createdAt: Carbon::parse(is_string($data['createdAt'] ?? null) || is_int($data['createdAt'] ?? null) ? $data['createdAt'] : 'now'),
            gameSystemName: is_string($data['gameSystemName'] ?? null) ? $data['gameSystemName'] : null,
            participantCount: is_int($data['participantCount'] ?? null) ? $data['participantCount'] : null,
            maxPlayers: is_int($data['maxPlayers'] ?? null) ? $data['maxPlayers'] : null,
            imageUrl: is_string($data['imageUrl'] ?? null) ? $data['imageUrl'] : null,
        );
    }
}

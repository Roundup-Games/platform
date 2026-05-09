<?php

use App\Dto\FeedItem;
use Carbon\Carbon;

describe('FeedItem DTO', function () {
    it('round-trips through toArray and fromArray', function () {
        $now = Carbon::parse('2025-07-12T10:30:00Z');

        $item = new FeedItem(
            id: 'game_created_42',
            type: 'game_created',
            entityType: 'game',
            entityId: '42',
            entityName: 'Friday Night D&D',
            userName: 'Alice',
            userId: '10',
            createdAt: $now,
            gameSystemName: 'D&D 5e',
            participantCount: 3,
            maxPlayers: 5,
            imageUrl: null,
        );

        $array = $item->toArray();
        $restored = FeedItem::fromArray($array);

        expect($restored->id)->toBe('game_created_42');
        expect($restored->type)->toBe('game_created');
        expect($restored->entityType)->toBe('game');
        expect($restored->entityId)->toBe('42');
        expect($restored->entityName)->toBe('Friday Night D&D');
        expect($restored->userName)->toBe('Alice');
        expect($restored->userId)->toBe('10');
        expect($restored->createdAt->toIso8601String())->toBe($now->toIso8601String());
        expect($restored->gameSystemName)->toBe('D&D 5e');
        expect($restored->participantCount)->toBe(3);
        expect($restored->maxPlayers)->toBe(5);
        expect($restored->imageUrl)->toBeNull();
    });

    it('serializes nullable fields as null when omitted', function () {
        $item = new FeedItem(
            id: 'player_joined_7',
            type: 'player_joined',
            entityType: 'game',
            entityId: '7',
            entityName: 'Quick Session',
            userName: 'Bob',
            userId: '20',
            createdAt: Carbon::now(),
        );

        $array = $item->toArray();

        expect($array['gameSystemName'])->toBeNull();
        expect($array['participantCount'])->toBeNull();
        expect($array['maxPlayers'])->toBeNull();
        expect($array['imageUrl'])->toBeNull();
    });

    it('handles fromArray with missing optional keys gracefully', function () {
        $data = [
            'id' => 'game_created_1',
            'type' => 'game_created',
            'entityType' => 'game',
            'entityId' => '1',
            'entityName' => 'Test Game',
            'userName' => 'Test User',
            'userId' => '1',
            'createdAt' => '2025-07-12T10:00:00Z',
            // No optional keys
        ];

        $item = FeedItem::fromArray($data);

        expect($item->gameSystemName)->toBeNull();
        expect($item->participantCount)->toBeNull();
        expect($item->maxPlayers)->toBeNull();
        expect($item->imageUrl)->toBeNull();
    });

    it('preserves exact ISO timestamp through serialization', function () {
        $timestamp = '2025-07-12T15:30:00+00:00';
        $item = new FeedItem(
            id: 'x',
            type: 'game_created',
            entityType: 'game',
            entityId: '1',
            entityName: 'Game',
            userName: 'User',
            userId: '1',
            createdAt: Carbon::parse($timestamp),
        );

        $restored = FeedItem::fromArray($item->toArray());

        expect($restored->createdAt->eq(Carbon::parse($timestamp)))->toBeTrue();
    });

    it('is JSON-serializable for cache storage', function () {
        $item = new FeedItem(
            id: 'campaign_created_5',
            type: 'campaign_created',
            entityType: 'campaign',
            entityId: '5',
            entityName: 'Long Campaign',
            userName: 'Carol',
            userId: '30',
            createdAt: Carbon::parse('2025-07-12T10:00:00Z'),
            gameSystemName: 'Pathfinder 2e',
            participantCount: 4,
            maxPlayers: 6,
            imageUrl: 'https://example.com/image.jpg',
        );

        $json = json_encode($item->toArray());
        $decoded = json_decode($json, true);

        expect($decoded)->toBeArray();
        $restored = FeedItem::fromArray($decoded);
        expect($restored->entityName)->toBe('Long Campaign');
        expect($restored->imageUrl)->toBe('https://example.com/image.jpg');
    });
});

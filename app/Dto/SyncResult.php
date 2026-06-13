<?php

namespace App\Dto;

/**
 * Result of syncing game systems from BoardGameGeek.
 *
 * Replaces the ad-hoc array{synced, failed, errors, discovered_expansion_ids}
 * shape from BggSyncService and BggSeedService.
 */
class SyncResult
{
    /**
     * @param  int  $synced  Number of successfully synced items
     * @param  int  $failed  Number of items that failed
     * @param  array<int|string, string>  $errors  Error messages keyed by identifier
     * @param  array<int, int>  $discoveredExpansionIds  BGG IDs of expansions discovered during sync
     */
    public function __construct(
        public readonly int $synced = 0,
        public readonly int $failed = 0,
        public readonly array $errors = [],
        public readonly array $discoveredExpansionIds = [],
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    public function withSynced(int $synced): self
    {
        return new self($synced, $this->failed, $this->errors, $this->discoveredExpansionIds);
    }

    public function withFailure(string $key, string $error): self
    {
        return new self(
            $this->synced,
            $this->failed + 1,
            [...$this->errors, $key => $error],
            $this->discoveredExpansionIds,
        );
    }

    /**
     * @param  array<int, int>  $ids
     */
    public function withDiscoveredExpansionIds(array $ids): self
    {
        return new self($this->synced, $this->failed, $this->errors, $ids);
    }

    public function merge(self $other): self
    {
        return new self(
            $this->synced + $other->synced,
            $this->failed + $other->failed,
            [...$this->errors, ...$other->errors],
            [...$this->discoveredExpansionIds, ...$other->discoveredExpansionIds],
        );
    }

    public function hasErrors(): bool
    {
        return $this->failed > 0;
    }

    /**
     * @return array{synced: int, failed: int, errors: array<int|string, string>, discovered_expansion_ids: array<int, int>}
     */
    public function toArray(): array
    {
        return [
            'synced' => $this->synced,
            'failed' => $this->failed,
            'errors' => $this->errors,
            'discovered_expansion_ids' => $this->discoveredExpansionIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $errors = is_array($data['errors'] ?? null) ? $data['errors'] : [];
        $expansionIds = is_array($data['discovered_expansion_ids'] ?? null) ? $data['discovered_expansion_ids'] : [];

        return new self(
            synced: is_int($data['synced'] ?? null) ? $data['synced'] : 0,
            failed: is_int($data['failed'] ?? null) ? $data['failed'] : 0,
            errors: array_filter($errors, 'is_string'),
            discoveredExpansionIds: array_filter($expansionIds, 'is_int'),
        );
    }
}

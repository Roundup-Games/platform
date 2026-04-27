<?php

namespace App\Dto;

class PwaEligibilityResult
{
    public function __construct(
        public readonly bool $eligible,
        public readonly string $reason,
        public readonly string $source,
    ) {}

    public static function notEligible(string $reason): self
    {
        return new self(
            eligible: false,
            reason: $reason,
            source: 'none',
        );
    }

    public static function eligibleViaScore(): self
    {
        return new self(
            eligible: true,
            reason: 'engagement_threshold_met',
            source: 'baseline+score',
        );
    }

    public static function eligibleViaTrypass(string $reason): self
    {
        return new self(
            eligible: true,
            reason: $reason,
            source: 'trypass',
        );
    }
}

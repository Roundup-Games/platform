<?php

namespace App\Dto;

/**
 * Lightweight result for participant operations that succeed or fail with a message key.
 */
class ParticipantResult
{
    /**
     * @param  array<string, bool|float|int|string|null>  $messageParams
     * @param  array<string, bool|float|int|string|null>  $errorParams
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $messageKey = '',
        public readonly array $messageParams = [],
        public readonly ?string $errorKey = null,
        public readonly array $errorParams = [],
    ) {}

    /** @param  array<string, bool|float|int|string|null>  $params */
    public static function ok(string $messageKey, array $params = []): self
    {
        return new self(true, $messageKey, $params);
    }

    /** @param  array<string, bool|float|int|string|null>  $params */
    public static function fail(string $errorKey, array $params = []): self
    {
        return new self(false, '', [], $errorKey, $params);
    }
}

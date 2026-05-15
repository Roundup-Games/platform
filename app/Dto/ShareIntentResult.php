<?php

namespace App\Dto;

/**
 * Internal result object for share intent processing.
 */
class ShareIntentResult
{
    public function __construct(
        public readonly bool $shouldRedirect,
        public readonly ?string $redirectRoute,
        public readonly bool $shouldClearCookie = false,
        public readonly ?string $entityId = null,
    ) {}
}

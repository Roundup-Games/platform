<?php

namespace App\Exceptions;

class BggApiException extends \RuntimeException
{
    public static function requestFailed(int $statusCode, string $url): self
    {
        return new self("BGG API request failed with status {$statusCode} for URL: {$url}");
    }

    public static function timeout(string $url): self
    {
        return new self("BGG API request timed out for URL: {$url}");
    }

    public static function notAuthenticated(): self
    {
        return new self('BGG API authentication failed. Check BGG_API_TOKEN configuration.');
    }
}

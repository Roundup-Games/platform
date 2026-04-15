<?php

namespace App\Exceptions;

class BggParseException extends \RuntimeException
{
    public static function fromXmlError(\Throwable $e): self
    {
        return new self("Failed to parse BGG XML: {$e->getMessage()}", 0, $e);
    }
}

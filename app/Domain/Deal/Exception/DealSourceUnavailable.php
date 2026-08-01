<?php

declare(strict_types=1);

namespace App\Domain\Deal\Exception;

use RuntimeException;
use Throwable;

/**
 * Raised by adapters when a source cannot be read. Transport-level exceptions
 * never leave the infrastructure layer.
 */
final class DealSourceUnavailable extends RuntimeException
{
    public static function forSource(string $source, string $reason, ?Throwable $previous = null): self
    {
        return new self(sprintf('Deal source [%s] is unavailable: %s', $source, $reason), 0, $previous);
    }
}

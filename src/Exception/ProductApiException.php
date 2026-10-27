<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Raised when the external products API cannot be used:
 * network error, timeout, unexpected HTTP status or invalid payload.
 */
final class ProductApiException extends \RuntimeException
{
    public static function unreachable(string $url, \Throwable $previous): self
    {
        return new self(sprintf('Products API unreachable (%s).', $url), 0, $previous);
    }

    public static function unexpectedStatus(string $url, int $status): self
    {
        return new self(sprintf('Products API returned HTTP %d (%s).', $status, $url));
    }

    public static function invalidPayload(string $url, string $reason, ?\Throwable $previous = null): self
    {
        return new self(sprintf('Products API returned an invalid payload (%s): %s.', $url, $reason), 0, $previous);
    }
}
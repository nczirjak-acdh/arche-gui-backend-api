<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use RuntimeException;

/** An ARCHE failure with a public message and an appropriate HTTP status. */
final class UpstreamError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 502,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}

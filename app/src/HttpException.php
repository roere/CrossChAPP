<?php

declare(strict_types=1);

final class HttpException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, $statusCode);
    }
}

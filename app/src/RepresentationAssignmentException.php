<?php

declare(strict_types=1);

final class RepresentationAssignmentException extends DomainException
{
    public function __construct(
        string $message,
        public readonly string $apiCode,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }
}

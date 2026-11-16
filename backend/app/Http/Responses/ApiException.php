<?php

namespace App\Http\Responses;

use RuntimeException;

/** Throw for an expected business error with a stable machine-readable code. */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly mixed $details = null,
    ) {
        parent::__construct($message);
    }
}

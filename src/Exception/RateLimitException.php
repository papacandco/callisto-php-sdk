<?php

declare(strict_types=1);

namespace Callisto\Sdk\Exception;

class RateLimitException extends CallistoException
{
    public function __construct(
        string $message,
        int $statusCode = 0,
        mixed $body = null,
        private readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $statusCode, $body);
    }

    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}

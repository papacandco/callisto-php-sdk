<?php

declare(strict_types=1);

namespace Callisto\Sdk\Exception;

use Exception;

class CallistoException extends Exception
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
        private readonly mixed $body = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): mixed
    {
        return $this->body;
    }

    public static function fromStatus(int $status, string $message, mixed $body = null, ?int $retryAfter = null): self
    {
        return match (true) {
            $status === 401 => new AuthenticationException($message, $status, $body),
            $status === 400, $status === 422 => new ValidationException($message, $status, $body),
            $status === 404 => new NotFoundException($message, $status, $body),
            $status === 429 => new RateLimitException($message, $status, $body, $retryAfter),
            default => new ApiException($message, $status, $body),
        };
    }
}

<?php

declare(strict_types=1);

namespace Callisto\Sdk\Error;

/**
 * Pluggable transport for delivering error events to the ingest DSN.
 *
 * Implementations MUST be best-effort: they should never throw. The default
 * {@see GuzzleSender} delivers synchronously with a short timeout and swallows
 * all failures. Tests inject a fake to capture the payload.
 */
interface Sender
{
    /**
     * @param array<string, mixed> $payload
     */
    public function send(string $dsn, array $payload): void;
}

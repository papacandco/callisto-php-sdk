<?php

declare(strict_types=1);

namespace Callisto\Sdk\Logs;

/**
 * Pluggable HTTP path for delivering one OTLP JSON batch to callisto-logs.
 *
 * It is separate from the messaging Transport and from the error reporter's
 * sender on purpose: it only ever carries the Logs key. The default
 * {@see GuzzleSender} posts synchronously with a short timeout. Tests inject a
 * fake to capture the request.
 *
 * An implementation returns the HTTP status code and may throw on a network
 * error or timeout: the {@see Logger} catches everything and drops the batch.
 */
interface Sender
{
    /**
     * @param array<string, string> $headers
     * @return int the HTTP status code of the response
     */
    public function send(string $url, array $headers, string $body): int;
}

<?php

declare(strict_types=1);

namespace Callisto\Sdk\Error;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\RequestOptions;

/**
 * Default {@see Sender} — POSTs the event to the DSN synchronously with a short
 * timeout (PHP has no portable background threads in a request context).
 *
 * Delivery is best-effort: any exception and any non-202 response is swallowed.
 * It uses its own dedicated Guzzle client and never inherits the main
 * transport's Basic-auth credentials.
 */
final class GuzzleSender implements Sender
{
    private GuzzleClient $http;

    public function __construct(
        ?GuzzleClient $http = null,
        private readonly float $timeout = 2.0,
    ) {
        $this->http = $http ?? new GuzzleClient();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function send(string $dsn, array $payload): void
    {
        $this->http->request('POST', $dsn, [
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            RequestOptions::JSON => $payload,
            RequestOptions::TIMEOUT => $this->timeout,
            RequestOptions::CONNECT_TIMEOUT => $this->timeout,
            RequestOptions::HTTP_ERRORS => false,
        ]);
    }
}

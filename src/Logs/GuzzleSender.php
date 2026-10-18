<?php

declare(strict_types=1);

namespace Callisto\Sdk\Logs;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\RequestOptions;

/**
 * Default {@see Sender}: POSTs the batch synchronously with a 2 s connect and
 * 3 s total timeout, so a slow ingest never holds a request for long.
 *
 * It uses its own Guzzle client and never inherits the messaging transport's
 * Basic-auth credentials. Non-2xx responses are returned, not thrown.
 */
final class GuzzleSender implements Sender
{
    public const CONNECT_TIMEOUT = 2.0;
    public const TIMEOUT = 3.0;

    private GuzzleClient $http;

    public function __construct(?GuzzleClient $http = null)
    {
        $this->http = $http ?? new GuzzleClient();
    }

    public function send(string $url, array $headers, string $body): int
    {
        $response = $this->http->request('POST', $url, [
            RequestOptions::HEADERS => $headers,
            RequestOptions::BODY => $body,
            RequestOptions::CONNECT_TIMEOUT => self::CONNECT_TIMEOUT,
            RequestOptions::TIMEOUT => self::TIMEOUT,
            RequestOptions::HTTP_ERRORS => false,
        ]);

        return $response->getStatusCode();
    }
}

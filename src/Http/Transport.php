<?php

declare(strict_types=1);

namespace Callisto\Sdk\Http;

use Callisto\Sdk\Config;
use Callisto\Sdk\Exception\CallistoException;
use Callisto\Sdk\Exception\NetworkException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\RequestOptions;

final class Transport
{
    private GuzzleClient $http;

    public function __construct(
        private readonly Config $config,
        ?GuzzleClient $http = null,
    ) {
        $this->http = $http ?? new GuzzleClient();
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>|null $query
     * @return mixed decoded JSON (associative array) or null
     */
    public function request(string $method, string $path, ?array $body = null, ?array $query = null): mixed
    {
        $url = $this->config->baseUrl . $path;

        $options = [
            RequestOptions::HEADERS => ['Accept' => 'application/json'],
            RequestOptions::AUTH => [$this->config->clientId, $this->config->apiKey],
            RequestOptions::TIMEOUT => $this->config->timeout,
            RequestOptions::HTTP_ERRORS => false,
        ];
        if ($query !== null) {
            $options[RequestOptions::QUERY] = array_filter(
                $query,
                static fn ($v) => $v !== null
            );
        }
        if ($body !== null) {
            $options[RequestOptions::JSON] = $body;
        }

        try {
            $response = $this->http->request($method, $url, $options);
        } catch (TransferException $e) {
            throw new NetworkException("Request to {$url} failed: " . $e->getMessage());
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $data = $raw === '' ? null : json_decode($raw, true);

        if ($status >= 400) {
            $message = is_array($data) && isset($data['message'])
                ? (string) $data['message']
                : "HTTP {$status}";
            $retryAfter = null;
            if ($status === 429) {
                $header = $response->getHeaderLine('Retry-After');
                $retryAfter = is_numeric($header) ? (int) $header : null;
            }
            throw CallistoException::fromStatus($status, $message, $data, $retryAfter);
        }

        return $data;
    }
}

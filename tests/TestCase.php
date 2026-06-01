<?php

declare(strict_types=1);

namespace Callisto\Sdk\Tests;

use Callisto\Sdk\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Http\Message\RequestInterface;

abstract class TestCase extends BaseTestCase
{
    protected array $history = [];

    /**
     * Build a Client whose Guzzle layer is a queue of canned responses.
     *
     * @param array<int, array{0:int,1:array,2?:array}> $responses
     *     [statusCode, jsonBody] or [statusCode, jsonBody, extraHeaders] tuples
     */
    protected function clientWith(array $responses): Client
    {
        $queue = array_map(
            fn (array $r) => new Response(
                $r[0],
                ['Content-Type' => 'application/json'] + ($r[2] ?? []),
                json_encode($r[1])
            ),
            $responses
        );
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $this->history = [];
        $stack->push(\GuzzleHttp\Middleware::history($this->history));

        $guzzle = new GuzzleClient(['handler' => $stack]);

        return new Client(
            clientId: 'cid',
            apiKey: 'secret',
            baseUrl: 'https://api.test/v1',
            httpClient: $guzzle,
        );
    }

    protected function lastRequest(): RequestInterface
    {
        return $this->history[count($this->history) - 1]['request'];
    }

    protected function requestAt(int $i): RequestInterface
    {
        return $this->history[$i]['request'];
    }
}

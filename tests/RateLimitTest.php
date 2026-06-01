<?php

declare(strict_types=1);

namespace Callisto\Sdk\Tests;

use Callisto\Sdk\Exception\RateLimitException;

class RateLimitTest extends TestCase
{
    public function testSurfacesRetryAfterHeader(): void
    {
        $client = $this->clientWith([
            [429, ['message' => 'Too Many Requests'], ['Retry-After' => '30']],
        ]);

        try {
            $client->balance()->get();
            $this->fail('Expected RateLimitException to be thrown.');
        } catch (RateLimitException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertSame(30, $e->getRetryAfter());
        }
    }

    public function testRetryAfterNullWhenHeaderAbsent(): void
    {
        $client = $this->clientWith([
            [429, ['message' => 'Too Many Requests']],
        ]);

        try {
            $client->balance()->get();
            $this->fail('Expected RateLimitException to be thrown.');
        } catch (RateLimitException $e) {
            $this->assertNull($e->getRetryAfter());
        }
    }
}

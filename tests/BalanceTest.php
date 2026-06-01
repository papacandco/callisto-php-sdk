<?php

declare(strict_types=1);

namespace Callisto\Sdk\Tests;

class BalanceTest extends TestCase
{
    public function testGetBalance(): void
    {
        $client = $this->clientWith([[200, ['credit' => 100.5, 'currency' => 'XOF']]]);
        $bal = $client->balance()->get();
        $this->assertSame(100.5, $bal['credit']);
        $this->assertStringContainsString('format=full', (string) $this->lastRequest()->getUri());
    }

    public function testGetBalanceWithCurrency(): void
    {
        $client = $this->clientWith([[200, ['credit' => 1, 'currency' => 'USD']]]);
        $client->balance()->get(format: 'short', currency: 'USD');
        $uri = (string) $this->lastRequest()->getUri();
        $this->assertStringContainsString('format=short', $uri);
        $this->assertStringContainsString('currency=USD', $uri);
    }
}

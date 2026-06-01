<?php

declare(strict_types=1);

namespace Callisto\Sdk\Tests;

use Callisto\Sdk\Exception\NotFoundException;

class TransportTest extends TestCase
{
    public function testSendsBasicAuthAndDecodesJson(): void
    {
        $client = $this->clientWith([[200, ['credit' => 1.0, 'currency' => 'XOF']]]);
        $client->balance()->get();

        $req = $this->lastRequest();
        $this->assertSame(
            'Basic ' . base64_encode('cid:secret'),
            $req->getHeaderLine('Authorization')
        );
        $this->assertSame('application/json', $req->getHeaderLine('Accept'));
        $this->assertSame('https://api.test/v1/sms/balance?format=full', (string) $req->getUri());
    }

    public function testSendsJsonBody(): void
    {
        $client = $this->clientWith([[200, [
            'total_amount' => 0, 'available_credit' => 0, 'status' => 'sent',
            'recipient_count' => 1, 'scheduled' => false, 'messages' => [],
        ]]]);
        $client->sms()->send(sender: 'Acme', to: '+225', message: 'hi');

        $req = $this->lastRequest();
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('application/json', $req->getHeaderLine('Content-Type'));
        $this->assertSame(
            ['sender' => 'Acme', 'to' => '+225', 'message' => 'hi'],
            json_decode((string) $req->getBody(), true)
        );
    }

    public function testMapsErrorStatus(): void
    {
        $client = $this->clientWith([[404, ['message' => 'Message not found']]]);
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Message not found');
        $client->sms()->getStatus('x');
    }
}

<?php

declare(strict_types=1);

namespace Callisto\Sdk\Tests;

use Callisto\Sdk\Exception\ValidationException;

class NotifyTest extends TestCase
{
    public function testSend(): void
    {
        $client = $this->clientWith([[200, [
            'status' => 'queued', 'topic' => 't', 'queued_events' => 1, 'topic_messages' => [],
        ]]]);
        $res = $client->notify()->send(topic: 'welcome', sms: [['to' => '+225']]);
        $body = json_decode((string) $this->lastRequest()->getBody(), true);
        $this->assertSame(['topic' => 'welcome', 'sms' => [['to' => '+225']]], $body);
        $this->assertSame('queued', $res['status']);
    }

    public function testRequiresEventBlock(): void
    {
        $client = $this->clientWith([]);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('event block');
        $client->notify()->send(topic: 'welcome');
    }
}

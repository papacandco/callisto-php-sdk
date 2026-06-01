<?php

declare(strict_types=1);

namespace Callisto\Sdk\Tests;

use Callisto\Sdk\Model\Paginated;
use Callisto\Sdk\Model\SmsMessage;

class SmsTest extends TestCase
{
    private const SEND = [
        'total_amount' => 5, 'available_credit' => 95, 'status' => 'sent',
        'recipient_count' => 1, 'scheduled' => false, 'messages' => [],
    ];

    public function testSend(): void
    {
        $client = $this->clientWith([[200, self::SEND]]);
        $res = $client->sms()->send(sender: 'Acme', to: '+2250700000000', message: 'Hi');
        $body = json_decode((string) $this->lastRequest()->getBody(), true);
        $this->assertSame(['sender' => 'Acme', 'to' => '+2250700000000', 'message' => 'Hi'], $body);
        $this->assertSame('sent', $res['status']);
    }

    public function testSendOptionalFields(): void
    {
        $client = $this->clientWith([[200, self::SEND]]);
        $client->sms()->send(
            sender: 'Acme',
            to: ['+225070', '+225071'],
            message: 'Hi',
            notifyUrl: 'https://x/hook',
            scheduledAt: '2026-06-02 10:00:00',
        );
        $body = json_decode((string) $this->lastRequest()->getBody(), true);
        $this->assertSame('https://x/hook', $body['notify_url']);
        $this->assertSame('2026-06-02 10:00:00', $body['scheduled_at']);
        $this->assertSame(['+225070', '+225071'], $body['to']);
    }

    public function testListAndStatus(): void
    {
        $client = $this->clientWith([
            [200, [
                'items' => [[
                    'id' => 'abc', 'sender_name' => 'Acme', 'recipient' => '+2250700000000',
                    'content' => 'Hi', 'status' => 'sent',
                    'created_at' => '2026-06-01 10:00:00', 'updated_at' => '2026-06-01 10:01:00',
                ]],
                'total' => 1, 'per_page' => 15, 'current_page' => 1,
                'next' => 1, 'previous' => 1, 'total_pages' => 1,
            ]],
            [200, [
                'id' => '123', 'sender_name' => 'Acme', 'recipient' => '+2250700000000',
                'content' => 'Hi', 'status' => 'delivered',
                'created_at' => '2026-06-01 10:00:00', 'updated_at' => '2026-06-01 10:01:00',
            ]],
        ]);
        $list = $client->sms()->list(page: 2, perPage: 50);
        $this->assertStringContainsString('page=2', (string) $this->lastRequest()->getUri());
        $this->assertInstanceOf(Paginated::class, $list);
        $this->assertSame(1, $list->total);
        $this->assertInstanceOf(SmsMessage::class, $list->items[0]);
        $this->assertSame('sent', $list->items[0]->status);

        $res = $client->sms()->getStatus('abc');
        $this->assertSame('https://api.test/v1/sms/abc', (string) $this->lastRequest()->getUri());
        $this->assertInstanceOf(SmsMessage::class, $res);
        $this->assertSame('delivered', $res->status);
        $this->assertSame('Acme', $res->senderName);
    }
}

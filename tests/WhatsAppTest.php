<?php

declare(strict_types=1);

namespace Callisto\Sdk\Tests;

use Callisto\Sdk\Enum\WhatsAppMediaType;
use Callisto\Sdk\Model\Paginated;
use Callisto\Sdk\Model\WhatsAppInstance;
use Callisto\Sdk\Model\WhatsAppMessage;

class WhatsAppTest extends TestCase
{
    private const SEND = [
        'id' => 'm1', 'instance_id' => 'i1', 'recipient' => [],
        'message_type' => 'text', 'status' => 'pending', 'scheduled' => false,
    ];

    private const INSTANCE = [
        'id' => 'inst_1', 'code' => 'inst_1', 'client_id' => 'c1', 'name' => 'Main',
        'phone_number' => '+2250700000000', 'phone_name' => 'Main Phone', 'status' => 'connected',
        'billing_status' => 'active', 'trial_days_remaining' => 0, 'monthly_fee' => 9.99,
        'messages_sent_today' => 2, 'messages_sent_month' => 40, 'daily_limit' => 1000,
        'last_message_at' => '2026-06-01 10:00:00', 'webhook_url' => 'https://x/hook',
        'is_active' => true, 'created_at' => '2026-05-01 10:00:00', 'updated_at' => '2026-06-01 10:00:00',
    ];

    private const MESSAGE = [
        'id' => 'msg_9', 'instance_id' => 'inst_1', 'client_id' => 'c1', 'api_client_id' => 'ac1',
        'recipient' => '+2250700000000', 'recipient_name' => 'Bob', 'message_type' => 'text',
        'content' => 'hi', 'media_url' => null, 'media_mimetype' => null, 'media_filename' => null,
        'extra_data' => [], 'direction' => 'outbound', 'status' => 'sent',
        'whatsapp_message_id' => 'wamid.x', 'error_code' => null, 'error_message' => null,
        'retry_count' => 0, 'is_billable' => true, 'cost' => 0.01,
        'sent_at' => '2026-06-01 10:00:00', 'delivered_at' => null, 'read_at' => null,
        'scheduled_at' => null, 'created_at' => '2026-06-01 10:00:00',
        'updated_at' => '2026-06-01 10:00:00', 'processor_identifier' => 'proc_1',
    ];

    public function testCreateAndReadInstances(): void
    {
        $client = $this->clientWith([
            [201, self::INSTANCE],
            [200, [
                'items' => [self::INSTANCE], 'total' => 1, 'per_page' => 15, 'current_page' => 1,
                'next' => null, 'previous' => null, 'total_pages' => 1,
            ]],
            [200, self::INSTANCE],
            [200, ['qr_code' => 'x']],
            [200, ['status' => 'connected']],
        ]);
        $created = $client->whatsApp()->createInstance(name: 'Main');
        $this->assertSame('POST', $this->requestAt(0)->getMethod());
        $this->assertSame('https://api.test/v1/whatsapp/instances', (string) $this->requestAt(0)->getUri());
        $this->assertInstanceOf(WhatsAppInstance::class, $created);
        $this->assertSame('inst_1', $created->code);
        $this->assertSame('connected', $created->status);

        $list = $client->whatsApp()->listInstances(page: 3);
        $this->assertStringContainsString('page=3', (string) $this->lastRequest()->getUri());
        $this->assertInstanceOf(Paginated::class, $list);
        $this->assertSame(1, $list->total);
        $this->assertInstanceOf(WhatsAppInstance::class, $list->items[0]);

        $instance = $client->whatsApp()->getInstance('inst_1');
        $this->assertSame('https://api.test/v1/whatsapp/inst_1', (string) $this->lastRequest()->getUri());
        $this->assertInstanceOf(WhatsAppInstance::class, $instance);
        $this->assertSame('Main', $instance->name);

        $qr = $client->whatsApp()->getQr('inst_1');
        $this->assertStringEndsWith('/qr', (string) $this->lastRequest()->getUri());
        $this->assertSame('x', $qr['qr_code']);

        $status = $client->whatsApp()->getStatus('inst_1');
        $this->assertStringEndsWith('/status', (string) $this->lastRequest()->getUri());
        $this->assertSame('connected', $status['status']);
    }

    public function testMessages(): void
    {
        $client = $this->clientWith([
            [200, [
                'items' => [self::MESSAGE], 'total' => 1, 'per_page' => 15, 'current_page' => 1,
                'next' => null, 'previous' => null, 'total_pages' => 1,
            ]],
            [200, self::MESSAGE],
        ]);
        $list = $client->whatsApp()->listMessages('inst_1', page: 2);
        $this->assertStringContainsString('page=2', (string) $this->lastRequest()->getUri());
        $this->assertInstanceOf(Paginated::class, $list);
        $this->assertSame(1, $list->total);
        $this->assertInstanceOf(WhatsAppMessage::class, $list->items[0]);

        $message = $client->whatsApp()->getMessage('msg_9');
        $this->assertSame('https://api.test/v1/whatsapp/messages/msg_9', (string) $this->lastRequest()->getUri());
        $this->assertInstanceOf(WhatsAppMessage::class, $message);
        $this->assertSame('msg_9', $message->id);
        $this->assertSame('sent', $message->status);
    }

    public function testSendVariants(): void
    {
        $client = $this->clientWith([
            [200, self::SEND], [200, self::SEND], [200, self::SEND],
            [200, self::SEND], [200, self::SEND],
        ]);
        $res = $client->whatsApp()->sendText('inst_1', to: '+225', message: 'hi');
        $this->assertSame('https://api.test/v1/whatsapp/inst_1/send/text', (string) $this->lastRequest()->getUri());
        $this->assertSame('m1', $res['id']);
        $client->whatsApp()->sendMedia('inst_1', to: '+225', type: WhatsAppMediaType::Image, mediaUrl: 'u');
        $this->assertSame('https://api.test/v1/whatsapp/inst_1/send/media', (string) $this->lastRequest()->getUri());
        $client->whatsApp()->sendButtons('inst_1', to: '+225', body: 'b', buttons: [['id' => '1', 'title' => 'Yes']]);
        $this->assertSame('https://api.test/v1/whatsapp/inst_1/send/buttons', (string) $this->lastRequest()->getUri());
        $client->whatsApp()->sendLocation('inst_1', to: '+225', latitude: 1.2, longitude: 3.4);
        $this->assertSame('https://api.test/v1/whatsapp/inst_1/send/location', (string) $this->lastRequest()->getUri());
        $client->whatsApp()->sendList('inst_1', to: '+225', body: 'b', buttonText: 'Open', sections: []);
        $this->assertSame('https://api.test/v1/whatsapp/inst_1/send/list', (string) $this->lastRequest()->getUri());
    }
}

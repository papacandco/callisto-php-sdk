<?php

declare(strict_types=1);

namespace Callisto\Sdk\Tests;

use Callisto\Sdk\Enum\OtpProvider;
use Callisto\Sdk\Exception\ValidationException;
use Callisto\Sdk\Model\Otp;
use Callisto\Sdk\Model\Paginated;

class OtpTest extends TestCase
{
    public function testSend(): void
    {
        $client = $this->clientWith([[200, [
            'id' => 'o1', 'provider' => 'sms', 'recipient' => [],
            'expires_at' => 'x', 'expires_in' => 300,
        ]]]);
        $res = $client->otp()->send(to: '2250700000000', message: 'Code {code}');
        $body = json_decode((string) $this->lastRequest()->getBody(), true);
        $this->assertSame(['to' => '2250700000000', 'message' => 'Code {code}'], $body);
        $this->assertSame('o1', $res['id']);
    }

    public function testWhatsappProviderRequiresInstanceCode(): void
    {
        $client = $this->clientWith([]);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('instance_code');
        $client->otp()->send(to: 'x', message: 'm', provider: OtpProvider::Whatsapp);
    }

    public function testVerify(): void
    {
        $client = $this->clientWith([[200, [
            'id' => 'o1', 'status' => 'verified', 'verified' => true, 'verified_at' => 'now',
        ]]]);
        $res = $client->otp()->verify(otpId: 'o1', code: '12345');
        $this->assertTrue($res['verified']);
    }

    public function testStatusAndList(): void
    {
        $client = $this->clientWith([
            [200, [
                'otp_id' => 'o1', 'status' => 'pending', 'recipient' => '+2250700000000',
                'expires_at' => '2026-06-01 10:05:00', 'verified_at' => null,
                'attempts' => 0, 'created_at' => '2026-06-01 10:00:00',
            ]],
            [200, [
                'items' => [[
                    'id' => 'o1', 'status' => 'pending', 'recipient' => '+2250700000000',
                    'expires_at' => '2026-06-01 10:05:00', 'verified_at' => null,
                    'attempts' => 0, 'created_at' => '2026-06-01 10:00:00',
                ]],
                'total' => 1, 'per_page' => 15, 'current_page' => 1,
                'next' => null, 'previous' => null, 'total_pages' => 1,
            ]],
        ]);
        $res = $client->otp()->getStatus('o1');
        $this->assertSame('https://api.test/v1/otps/o1', (string) $this->lastRequest()->getUri());
        $this->assertInstanceOf(Otp::class, $res);
        $this->assertSame('o1', $res->otpId);
        $this->assertSame('pending', $res->status);

        $list = $client->otp()->list(page: 1, limit: 10);
        $this->assertStringContainsString('limit=10', (string) $this->lastRequest()->getUri());
        $this->assertInstanceOf(Paginated::class, $list);
        $this->assertSame(1, $list->total);
        $this->assertInstanceOf(Otp::class, $list->items[0]);
        $this->assertSame('o1', $list->items[0]->id);
    }
}

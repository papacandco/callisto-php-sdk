<?php

declare(strict_types=1);

namespace Callisto\Sdk\Resource;

use Callisto\Sdk\Enum\WhatsAppMediaType;
use Callisto\Sdk\Http\Transport;
use Callisto\Sdk\Model\Paginated;
use Callisto\Sdk\Model\WhatsAppInstance;
use Callisto\Sdk\Model\WhatsAppMessage;

final class WhatsApp
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /** @param array<string, mixed> $data */
    private static function pruned(array $data): array
    {
        return array_filter($data, static fn ($v) => $v !== null);
    }

    public function createInstance(
        string $name,
        ?string $phoneNumber = null,
        ?string $webhookUrl = null,
        ?string $idempotencyKey = null,
    ): WhatsAppInstance {
        return WhatsAppInstance::fromArray($this->transport->request('POST', '/whatsapp/instances', self::pruned([
            'name' => $name,
            'phone_number' => $phoneNumber,
            'webhook_url' => $webhookUrl,
            'idempotency_key' => $idempotencyKey,
        ])));
    }

    /** @return Paginated paginated list of {@see WhatsAppInstance} */
    public function listInstances(int $page = 1): Paginated
    {
        return Paginated::fromArray(
            $this->transport->request('GET', '/whatsapp/instances', null, ['page' => $page]),
            [WhatsAppInstance::class, 'fromArray'],
        );
    }

    public function getInstance(string $code): WhatsAppInstance
    {
        return WhatsAppInstance::fromArray($this->transport->request('GET', '/whatsapp/' . rawurlencode($code)));
    }

    /** @return array<string, mixed> */
    public function getQr(string $code): array
    {
        return $this->transport->request('GET', '/whatsapp/' . rawurlencode($code) . '/qr');
    }

    /** @return array<string, mixed> */
    public function getStatus(string $code): array
    {
        return $this->transport->request('GET', '/whatsapp/' . rawurlencode($code) . '/status');
    }

    /** @return Paginated paginated list of {@see WhatsAppMessage} */
    public function listMessages(
        string $code,
        ?string $startedAt = null,
        ?string $endedAt = null,
        ?int $page = null,
        ?int $perPage = null,
    ): Paginated {
        return Paginated::fromArray($this->transport->request(
            'GET',
            '/whatsapp/' . rawurlencode($code) . '/messages',
            null,
            [
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'page' => $page,
                'per_page' => $perPage,
            ],
        ), [WhatsAppMessage::class, 'fromArray']);
    }

    public function getMessage(string $messageId): WhatsAppMessage
    {
        return WhatsAppMessage::fromArray(
            $this->transport->request('GET', '/whatsapp/messages/' . rawurlencode($messageId)),
        );
    }

    /** @return array<string, mixed> */
    public function sendText(string $code, string $to, string $message, ?string $scheduledAt = null): array
    {
        return $this->transport->request('POST', '/whatsapp/' . rawurlencode($code) . '/send/text', self::pruned([
            'to' => $to,
            'message' => $message,
            'scheduled_at' => $scheduledAt,
        ]));
    }

    /** @return array<string, mixed> */
    public function sendMedia(
        string $code,
        string $to,
        WhatsAppMediaType|string $type,
        string $mediaUrl,
        ?string $caption = null,
        ?string $filename = null,
        ?string $scheduledAt = null,
    ): array {
        return $this->transport->request('POST', '/whatsapp/' . rawurlencode($code) . '/send/media', self::pruned([
            'to' => $to,
            'type' => $type instanceof WhatsAppMediaType ? $type->value : $type,
            'media_url' => $mediaUrl,
            'caption' => $caption,
            'filename' => $filename,
            'scheduled_at' => $scheduledAt,
        ]));
    }

    /**
     * @param array<int, array<string, mixed>> $buttons
     * @return array<string, mixed>
     */
    public function sendButtons(
        string $code,
        string $to,
        string $body,
        array $buttons,
        ?string $header = null,
        ?string $footer = null,
        ?string $scheduledAt = null,
    ): array {
        return $this->transport->request('POST', '/whatsapp/' . rawurlencode($code) . '/send/buttons', self::pruned([
            'to' => $to,
            'body' => $body,
            'buttons' => $buttons,
            'header' => $header,
            'footer' => $footer,
            'scheduled_at' => $scheduledAt,
        ]));
    }

    /** @return array<string, mixed> */
    public function sendLocation(
        string $code,
        string $to,
        float $latitude,
        float $longitude,
        ?string $name = null,
        ?string $address = null,
        ?string $scheduledAt = null,
    ): array {
        return $this->transport->request('POST', '/whatsapp/' . rawurlencode($code) . '/send/location', self::pruned([
            'to' => $to,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'name' => $name,
            'address' => $address,
            'scheduled_at' => $scheduledAt,
        ]));
    }

    /**
     * @param array<int, array<string, mixed>> $sections
     * @return array<string, mixed>
     */
    public function sendList(
        string $code,
        string $to,
        string $body,
        string $buttonText,
        array $sections,
        ?string $header = null,
        ?string $footer = null,
        ?string $scheduledAt = null,
    ): array {
        return $this->transport->request('POST', '/whatsapp/' . rawurlencode($code) . '/send/list', self::pruned([
            'to' => $to,
            'body' => $body,
            'button_text' => $buttonText,
            'sections' => $sections,
            'header' => $header,
            'footer' => $footer,
            'scheduled_at' => $scheduledAt,
        ]));
    }
}

<?php

declare(strict_types=1);

namespace Callisto\Sdk\Model;

final readonly class WhatsAppMessage
{
    /** @param array<string, mixed>|null $extraData */
    public function __construct(
        public string $id,
        public ?string $instanceId,
        public ?string $clientId,
        public ?string $clientApiId,
        public ?string $recipient,
        public ?string $recipientName,
        public ?string $messageType,
        public ?string $content,
        public ?string $mediaUrl,
        public ?string $mediaMimetype,
        public ?string $mediaFilename,
        public ?array $extraData,
        public ?string $direction,
        public ?string $status,
        public ?string $whatsappMessageId,
        public ?int $errorCode,
        public ?string $errorMessage,
        public ?int $retryCount,
        public ?bool $isBillable,
        public ?float $cost,
        public ?string $sentAt,
        public ?string $deliveredAt,
        public ?string $readAt,
        public ?string $scheduledAt,
        public ?string $createdAt,
        public ?string $updatedAt,
        public ?string $processorIdentifier,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            instanceId: $data['instance_id'] ?? null,
            clientId: $data['client_id'] ?? null,
            clientApiId: $data['client_api_id'] ?? null,
            recipient: $data['recipient'] ?? null,
            recipientName: $data['recipient_name'] ?? null,
            messageType: $data['message_type'] ?? null,
            content: $data['content'] ?? null,
            mediaUrl: $data['media_url'] ?? null,
            mediaMimetype: $data['media_mimetype'] ?? null,
            mediaFilename: $data['media_filename'] ?? null,
            extraData: $data['extra_data'] ?? null,
            direction: $data['direction'] ?? null,
            status: $data['status'] ?? null,
            whatsappMessageId: $data['whatsapp_message_id'] ?? null,
            errorCode: isset($data['error_code']) ? (int) $data['error_code'] : null,
            errorMessage: $data['error_message'] ?? null,
            retryCount: isset($data['retry_count']) ? (int) $data['retry_count'] : null,
            isBillable: isset($data['is_billable']) ? (bool) $data['is_billable'] : null,
            cost: isset($data['cost']) ? (float) $data['cost'] : null,
            sentAt: $data['sent_at'] ?? null,
            deliveredAt: $data['delivered_at'] ?? null,
            readAt: $data['read_at'] ?? null,
            scheduledAt: $data['scheduled_at'] ?? null,
            createdAt: $data['created_at'] ?? null,
            updatedAt: $data['updated_at'] ?? null,
            processorIdentifier: $data['processor_identifier'] ?? null,
        );
    }
}

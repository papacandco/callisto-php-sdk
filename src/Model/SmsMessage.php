<?php

declare(strict_types=1);

namespace Callisto\Sdk\Model;

final readonly class SmsMessage
{
    public function __construct(
        public string $id,
        public ?string $senderName,
        public ?string $recipient,
        public ?string $content,
        public ?string $status,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            senderName: $data['sender_name'] ?? null,
            recipient: $data['recipient'] ?? null,
            content: $data['content'] ?? null,
            status: $data['status'] ?? null,
            createdAt: $data['created_at'] ?? null,
            updatedAt: $data['updated_at'] ?? null,
        );
    }
}

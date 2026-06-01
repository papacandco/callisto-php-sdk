<?php

declare(strict_types=1);

namespace Callisto\Sdk\Model;

final readonly class Otp
{
    public function __construct(
        public ?string $otpId,
        public ?string $id,
        public ?string $status,
        public ?string $recipient,
        public ?string $expiresAt,
        public ?string $verifiedAt,
        public ?int $attempts,
        public ?string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            otpId: $data['otp_id'] ?? null,
            id: $data['id'] ?? null,
            status: $data['status'] ?? null,
            recipient: $data['recipient'] ?? null,
            expiresAt: $data['expires_at'] ?? null,
            verifiedAt: $data['verified_at'] ?? null,
            attempts: isset($data['attempts']) ? (int) $data['attempts'] : null,
            createdAt: $data['created_at'] ?? null,
        );
    }
}

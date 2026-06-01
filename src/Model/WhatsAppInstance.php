<?php

declare(strict_types=1);

namespace Callisto\Sdk\Model;

final readonly class WhatsAppInstance
{
    public function __construct(
        public string $id,
        public ?string $code,
        public ?string $clientId,
        public ?string $name,
        public ?string $phoneNumber,
        public ?string $phoneName,
        public ?string $status,
        public ?string $billingStatus,
        public ?int $trialDaysRemaining,
        public ?float $monthlyFee,
        public ?int $messagesSentToday,
        public ?int $messagesSentMonth,
        public ?int $dailyLimit,
        public ?string $lastMessageAt,
        public ?string $webhookUrl,
        public ?bool $isActive,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            code: $data['code'] ?? null,
            clientId: $data['client_id'] ?? null,
            name: $data['name'] ?? null,
            phoneNumber: $data['phone_number'] ?? null,
            phoneName: $data['phone_name'] ?? null,
            status: $data['status'] ?? null,
            billingStatus: $data['billing_status'] ?? null,
            trialDaysRemaining: isset($data['trial_days_remaining']) ? (int) $data['trial_days_remaining'] : null,
            monthlyFee: isset($data['monthly_fee']) ? (float) $data['monthly_fee'] : null,
            messagesSentToday: isset($data['messages_sent_today']) ? (int) $data['messages_sent_today'] : null,
            messagesSentMonth: isset($data['messages_sent_month']) ? (int) $data['messages_sent_month'] : null,
            dailyLimit: isset($data['daily_limit']) ? (int) $data['daily_limit'] : null,
            lastMessageAt: $data['last_message_at'] ?? null,
            webhookUrl: $data['webhook_url'] ?? null,
            isActive: isset($data['is_active']) ? (bool) $data['is_active'] : null,
            createdAt: $data['created_at'] ?? null,
            updatedAt: $data['updated_at'] ?? null,
        );
    }
}

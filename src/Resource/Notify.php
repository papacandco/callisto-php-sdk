<?php

declare(strict_types=1);

namespace Callisto\Sdk\Resource;

use Callisto\Sdk\Exception\ValidationException;
use Callisto\Sdk\Http\Transport;

final class Notify
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * @param array<int, array<string, mixed>>|null $email
     * @param array<int, array<string, mixed>>|null $sms
     * @param array<int, array<string, mixed>>|null $mobilePush
     * @param array<int, array<string, mixed>>|null $webPush
     * @param array<int, array<string, mixed>>|null $webhook
     * @param array<int, array<string, mixed>>|null $messaging
     * @param array<int, array<string, mixed>>|null $realTime
     * @return array<string, mixed>
     */
    public function send(
        string $topic,
        ?array $email = null,
        ?array $sms = null,
        ?array $mobilePush = null,
        ?array $webPush = null,
        ?array $webhook = null,
        ?array $messaging = null,
        ?array $realTime = null,
    ): array {
        $blocks = [
            'email' => $email,
            'sms' => $sms,
            'mobile_push' => $mobilePush,
            'web_push' => $webPush,
            'webhook' => $webhook,
            'messaging' => $messaging,
            'real_time' => $realTime,
        ];
        $present = array_filter($blocks, static fn ($v) => !empty($v));
        if ($present === []) {
            $error = new ValidationException(
                'At least one event block (email, sms, mobile_push, web_push, '
                . 'webhook, messaging, real_time) must be provided.'
            );
            $this->transport->reporter()?->captureException($error);
            throw $error;
        }

        return $this->transport->request('POST', '/notify/send', ['topic' => $topic] + $present);
    }
}

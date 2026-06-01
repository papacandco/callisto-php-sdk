<?php

declare(strict_types=1);

namespace Callisto\Sdk\Resource;

use Callisto\Sdk\Http\Transport;
use Callisto\Sdk\Model\Paginated;
use Callisto\Sdk\Model\SmsMessage;

final class Sms
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * @param string|array<int, string> $to
     * @return array<string, mixed>
     */
    public function send(
        string $sender,
        string|array $to,
        string $message,
        ?string $notifyUrl = null,
        ?string $scheduledAt = null,
    ): array {
        $body = ['sender' => $sender, 'to' => $to, 'message' => $message];
        if ($notifyUrl !== null) {
            $body['notify_url'] = $notifyUrl;
        }
        if ($scheduledAt !== null) {
            $body['scheduled_at'] = $scheduledAt;
        }

        return $this->transport->request('POST', '/sms/send', $body);
    }

    /**
     * @return Paginated paginated list of {@see SmsMessage}
     */
    public function list(
        ?string $startedAt = null,
        ?string $endedAt = null,
        ?int $page = null,
        ?int $perPage = null,
    ): Paginated {
        return Paginated::fromArray($this->transport->request('GET', '/sms/messages', null, [
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'page' => $page,
            'per_page' => $perPage,
        ]), [SmsMessage::class, 'fromArray']);
    }

    public function getStatus(string $messageId): SmsMessage
    {
        return SmsMessage::fromArray($this->transport->request('GET', '/sms/' . rawurlencode($messageId)));
    }
}

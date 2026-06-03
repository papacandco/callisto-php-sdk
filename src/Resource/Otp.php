<?php

declare(strict_types=1);

namespace Callisto\Sdk\Resource;

use Callisto\Sdk\Enum\OtpProvider;
use Callisto\Sdk\Enum\OtpType;
use Callisto\Sdk\Exception\ValidationException;
use Callisto\Sdk\Http\Transport;
use Callisto\Sdk\Model\Otp as OtpModel;
use Callisto\Sdk\Model\Paginated;

final class Otp
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function send(
        string $to,
        string $message,
        ?string $sender = null,
        ?int $expiredIn = null,
        OtpType|string|null $type = null,
        ?int $digitSize = null,
        OtpProvider|string|null $provider = null,
        ?string $instanceCode = null,
    ): array {
        $providerValue = $provider instanceof OtpProvider ? $provider->value : $provider;
        if ($providerValue === OtpProvider::Whatsapp->value && !$instanceCode) {
            $error = new ValidationException('instance_code is required when provider is whatsapp');
            $this->transport->reporter()?->captureException($error);
            throw $error;
        }

        $body = ['to' => $to, 'message' => $message];
        if ($sender !== null) {
            $body['sender'] = $sender;
        }
        if ($expiredIn !== null) {
            $body['expired_in'] = $expiredIn;
        }
        if ($type !== null) {
            $body['type'] = $type instanceof OtpType ? $type->value : $type;
        }
        if ($digitSize !== null) {
            $body['digit_size'] = $digitSize;
        }
        if ($providerValue !== null) {
            $body['provider'] = $providerValue;
        }
        if ($instanceCode !== null) {
            $body['instanceCode'] = $instanceCode;
        }

        return $this->transport->request('POST', '/otp/send', $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(string $otpId, string $code): array
    {
        return $this->transport->request('POST', '/otp/verify', [
            'otp_id' => $otpId,
            'code' => $code,
        ]);
    }

    public function getStatus(string $otpId): OtpModel
    {
        return OtpModel::fromArray($this->transport->request('GET', '/otps/' . rawurlencode($otpId)));
    }

    /**
     * @return Paginated paginated list of {@see OtpModel}
     */
    public function list(
        ?string $startedAt = null,
        ?string $endedAt = null,
        ?int $page = null,
        ?int $limit = null,
    ): Paginated {
        return Paginated::fromArray($this->transport->request('GET', '/otps', null, [
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'page' => $page,
            'limit' => $limit,
        ]), [OtpModel::class, 'fromArray']);
    }
}

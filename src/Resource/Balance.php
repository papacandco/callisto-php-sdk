<?php

declare(strict_types=1);

namespace Callisto\Sdk\Resource;

use Callisto\Sdk\Http\Transport;

final class Balance
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $format = 'full', ?string $currency = null): array
    {
        return $this->transport->request('GET', '/sms/balance', null, [
            'format' => $format,
            'currency' => $currency,
        ]);
    }
}

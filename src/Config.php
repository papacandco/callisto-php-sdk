<?php

declare(strict_types=1);

namespace Callisto\Sdk;

final class Config
{
    public const DEFAULT_BASE_URL = 'https://api.callistosignal.com/v1';

    public function __construct(
        public readonly string $clientId,
        public readonly string $apiKey,
        public readonly string $baseUrl,
        public readonly float $timeout,
    ) {
    }

    public static function resolve(
        ?string $clientId = null,
        ?string $apiKey = null,
        ?string $baseUrl = null,
        float $timeout = 30.0,
    ): self {
        $clientId = $clientId ?? getenv('CALLISTO_CLIENT_ID') ?: null;
        $apiKey = $apiKey ?? getenv('CALLISTO_API_KEY') ?: null;
        if (!$clientId || !$apiKey) {
            throw new \InvalidArgumentException(
                'Callisto: clientId and apiKey are required '
                . '(pass arguments or set CALLISTO_CLIENT_ID / CALLISTO_API_KEY).'
            );
        }
        $baseUrl = $baseUrl ?? (getenv('CALLISTO_BASE_URL') ?: null) ?? self::DEFAULT_BASE_URL;
        $baseUrl = rtrim($baseUrl, '/');

        return new self($clientId, $apiKey, $baseUrl, $timeout);
    }
}

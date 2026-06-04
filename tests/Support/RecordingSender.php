<?php

declare(strict_types=1);

namespace Callisto\Sdk\Tests\Support;

use Callisto\Sdk\Error\Sender;

/**
 * Test sender that records every payload it is handed.
 */
final class RecordingSender implements Sender
{
    /** @var list<array<string, mixed>> */
    public array $payloads = [];

    public function send(string $dsn, array $payload): void
    {
        $this->payloads[] = $payload;
    }

    /** @return array<string, mixed>|null */
    public function last(): ?array
    {
        return $this->payloads[count($this->payloads) - 1] ?? null;
    }
}

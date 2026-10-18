<?php

declare(strict_types=1);

namespace Callisto\Sdk\Tests\Support;

use Callisto\Sdk\Logs\Sender;
use RuntimeException;

/**
 * Fake logs sender: records every request and answers with queued statuses
 * (200 once the queue is empty). A status of 0 simulates a network error.
 */
final class RecordingLogsSender implements Sender
{
    /** @var list<array{url: string, headers: array<string, string>, body: string}> */
    public array $requests = [];

    /** @var list<int> */
    public array $statuses = [];

    /** @var (callable(): void)|null run inside send(), to test recursion */
    public $during = null;

    public function send(string $url, array $headers, string $body): int
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
        if ($this->during !== null) {
            ($this->during)();
        }
        $status = array_shift($this->statuses) ?? 200;
        if ($status === 0) {
            throw new RuntimeException('connection refused');
        }

        return $status;
    }

    /** @return array<string, mixed> */
    public function payload(int $i = -1): array
    {
        $request = $this->requests[$i < 0 ? count($this->requests) + $i : $i];

        return json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> */
    public function records(int $i = -1): array
    {
        return $this->payload($i)['resourceLogs'][0]['scopeLogs'][0]['logRecords'];
    }
}

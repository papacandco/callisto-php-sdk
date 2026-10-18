<?php

declare(strict_types=1);

namespace Callisto\Sdk\Tests;

use Callisto\Sdk\Logs\Logger;
use Callisto\Sdk\Logs\PsrLogger;
use Callisto\Sdk\Tests\Support\RecordingLogsSender;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;

class PsrLoggerTest extends BaseTestCase
{
    private RecordingLogsSender $sender;
    private Logger $logger;
    private PsrLogger $psr;

    protected function setUp(): void
    {
        $this->sender = new RecordingLogsSender();
        $this->logger = new Logger(
            accessKey: LoggerTest::PK,
            secret: LoggerTest::SK,
            endpoint: 'https://logs.test/v1/logs',
            serviceName: 'checkout',
            sender: $this->sender,
            clock: static fn (): int => 1760745600 * 1_000_000_000,
        );
        $this->psr = new PsrLogger($this->logger, 'app');
    }

    /** @return array<string, mixed> */
    private function only(): array
    {
        $this->logger->flush();
        $this->assertCount(1, $this->sender->requests);
        $records = $this->sender->records();
        $this->assertCount(1, $records);

        return $records[0];
    }

    /** @return array<string, array<string, mixed>> */
    private function attributes(array $record): array
    {
        $out = [];
        foreach ($record['attributes'] ?? [] as $kv) {
            $out[$kv['key']] = $kv['value'];
        }

        return $out;
    }

    public function testIsAPsr3Logger(): void
    {
        $this->assertInstanceOf(LoggerInterface::class, $this->psr);
        $this->assertSame($this->logger, $this->psr->logger());
    }

    /** @return array<string, array{string, string}> */
    public static function levels(): array
    {
        return [
            'debug' => [LogLevel::DEBUG, 'DEBUG'],
            'info' => [LogLevel::INFO, 'INFO'],
            'notice' => [LogLevel::NOTICE, 'INFO'],
            'warning' => [LogLevel::WARNING, 'WARN'],
            'error' => [LogLevel::ERROR, 'ERROR'],
            'critical' => [LogLevel::CRITICAL, 'ERROR'],
            'alert' => [LogLevel::ALERT, 'ERROR'],
            'emergency' => [LogLevel::EMERGENCY, 'FATAL'],
        ];
    }

    #[DataProvider('levels')]
    public function testLevelMappingViaMethods(string $level, string $severity): void
    {
        $this->psr->{$level}('x');
        $this->assertSame($severity, $this->only()['severityText']);
    }

    #[DataProvider('levels')]
    public function testLevelMappingViaLog(string $level, string $severity): void
    {
        $this->psr->log($level, 'x');
        $this->assertSame($severity, $this->only()['severityText']);
    }

    public function testUnknownLevelNeverThrows(): void
    {
        $this->psr->log('verbose', 'x');
        $this->psr->log(42, 'y');
        $this->logger->flush();
        $records = $this->sender->records();
        $this->assertSame('INFO', $records[0]['severityText']);
        $this->assertSame('INFO', $records[1]['severityText']);
    }

    public function testInterpolationAndAttributes(): void
    {
        $this->psr->info('User {user} paid {amount} ({missing}) {list}', [
            'user' => 'awa',
            'amount' => 1500,
            'list' => [1, 2],
            'trace_id' => '5B8EFFF798038103D269B633813FC60C',
        ]);
        $record = $this->only();
        $attrs = $this->attributes($record);

        $this->assertSame('User awa paid 1500 ({missing}) {list}', $record['body']['stringValue']);
        $this->assertSame(['stringValue' => 'app'], $attrs['logger.name']);
        $this->assertSame(['stringValue' => 'awa'], $attrs['user']);
        $this->assertSame(['intValue' => '1500'], $attrs['amount']);
        $this->assertArrayHasKey('arrayValue', $attrs['list']);
        $this->assertSame('5b8efff798038103d269b633813fc60c', $record['traceId']);
        $this->assertArrayNotHasKey('trace_id', $attrs);
    }

    public function testExceptionFields(): void
    {
        $this->psr->error('charge failed', ['exception' => new RuntimeException('card declined'), 'order' => 7]);
        $attrs = $this->attributes($this->only());

        $this->assertSame(['stringValue' => RuntimeException::class], $attrs['exception.type']);
        $this->assertSame(['stringValue' => 'card declined'], $attrs['exception.message']);
        $this->assertStringContainsString('card declined', $attrs['exception.stacktrace']['stringValue']);
        $this->assertStringContainsString(__FILE__, $attrs['exception.stacktrace']['stringValue']);
        $this->assertArrayNotHasKey('exception', $attrs);
        $this->assertSame(['intValue' => '7'], $attrs['order']);
    }

    public function testNonThrowableExceptionKeyIsAnOrdinaryAttribute(): void
    {
        $this->psr->error('x', ['exception' => 'just a string']);
        $attrs = $this->attributes($this->only());
        $this->assertSame(['stringValue' => 'just a string'], $attrs['exception']);
        $this->assertArrayNotHasKey('exception.type', $attrs);
    }

    public function testNoNameMeansNoLoggerNameAttribute(): void
    {
        (new PsrLogger($this->logger))->info('x');
        $this->assertArrayNotHasKey('logger.name', $this->attributes($this->only()));
    }

    public function testNoRecursionWhileSending(): void
    {
        $psr = $this->psr;
        // An HTTP library logging into the same PSR-3 logger during the send.
        $this->sender->during = static function () use ($psr): void {
            $psr->warning('http request sent');
        };
        $this->psr->info('a');
        $this->logger->flush();

        $this->sender->during = null;
        $this->logger->flush();
        $this->assertCount(1, $this->sender->requests);
        $this->assertSame('a', $this->sender->records()[0]['body']['stringValue']);
    }

    public function testNoRecursionThroughContextStringification(): void
    {
        $psr = $this->psr;
        $sneaky = new class ($psr) {
            public function __construct(private PsrLogger $psr)
            {
            }

            public function __toString(): string
            {
                $this->psr->info('logged from __toString');

                return 'sneaky';
            }
        };
        $this->psr->info('value {v}', ['v' => $sneaky]);
        $record = $this->only();
        $this->assertSame('value sneaky', $record['body']['stringValue']);
    }

    public function testDisabledLoggerIsANoOp(): void
    {
        $psr = new PsrLogger(new Logger(sender: $this->sender));
        $psr->critical('x', ['exception' => new RuntimeException('boom')]);
        $this->assertSame([], $this->sender->requests);
    }
}

<?php

declare(strict_types=1);

namespace Callisto\Sdk\Tests;

use Callisto\Sdk\Client;
use Callisto\Sdk\ErrorReporter;
use Callisto\Sdk\Logs\GuzzleSender;
use Callisto\Sdk\Logs\Logger;
use Callisto\Sdk\Tests\Support\RecordingLogsSender;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as BaseTestCase;

enum LogTestColor: string
{
    case Red = 'red';
}

class LoggerTest extends BaseTestCase
{
    /** The shared cross-SDK test vector. */
    public const PK = 'pk_00112233445566778899aabbccddeeff';
    public const SK = 'sk_000102030405060708090a0b0c0d0e0f1011121314151617';
    private const ENDPOINT = 'https://logs.test/v1/logs';
    private const T0 = 1760745600 * 1_000_000_000;

    private const ENV = [
        'CALLISTO_LOGS_ACCESS_KEY', 'CALLISTO_LOGS_SECRET', 'CALLISTO_LOGS_ENDPOINT',
        'CALLISTO_SERVICE_NAME', 'OTEL_SERVICE_NAME', 'CALLISTO_ENVIRONMENT',
        'CALLISTO_CLIENT_ID', 'CALLISTO_API_KEY', 'CALLISTO_APP_ERROR_DSN',
    ];

    private RecordingLogsSender $sender;
    private int $now = self::T0;

    protected function setUp(): void
    {
        $this->clearEnv();
        $this->sender = new RecordingLogsSender();
        $this->now = self::T0;
    }

    protected function tearDown(): void
    {
        $this->clearEnv();
    }

    private function clearEnv(): void
    {
        foreach (self::ENV as $name) {
            putenv($name);
        }
    }

    /** @param array<string, mixed> $options */
    private function logger(array $options = []): Logger
    {
        return new Logger(...$options + [
            'accessKey' => self::PK,
            'secret' => self::SK,
            'endpoint' => self::ENDPOINT,
            'serviceName' => 'checkout',
            'environment' => 'production',
            'sender' => $this->sender,
            'clock' => fn (): int => $this->now,
        ]);
    }

    /** @return array<string, mixed> */
    private function only(Logger $logger): array
    {
        $logger->flush();
        $this->assertCount(1, $this->sender->requests);
        $records = $this->sender->records();
        $this->assertCount(1, $records);

        return $records[0];
    }

    /** @return array<string, array<string, mixed>> attributes by key */
    private function attributes(array $record): array
    {
        $out = [];
        foreach ($record['attributes'] ?? [] as $kv) {
            $out[$kv['key']] = $kv['value'];
        }

        return $out;
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map([self::class, 'sortKeys'], $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    // 1. Golden payload
    public function testGoldenPayload(): void
    {
        $logger = $this->logger();
        $logger->info('order paid', [
            'order_id' => 42,
            'paid' => true,
            'trace_id' => '5B8EFFF798038103D269B633813FC60C',
            'span_id' => 'EEE19B7EC3C1B174',
        ]);
        $logger->flush();

        $golden = <<<'JSON'
        {
          "resourceLogs": [{
            "resource": { "attributes": [
              { "key": "service.name", "value": { "stringValue": "checkout" } },
              { "key": "deployment.environment.name", "value": { "stringValue": "production" } },
              { "key": "telemetry.sdk.name", "value": { "stringValue": "callisto-sdk" } },
              { "key": "telemetry.sdk.language", "value": { "stringValue": "php" } },
              { "key": "telemetry.sdk.version", "value": { "stringValue": "1.4.0" } }
            ]},
            "scopeLogs": [{
              "scope": { "name": "callisto-sdk", "version": "1.4.0" },
              "logRecords": [{
                "timeUnixNano": "1760745600000000000",
                "observedTimeUnixNano": "1760745600000000000",
                "severityNumber": 9,
                "severityText": "INFO",
                "body": { "stringValue": "order paid" },
                "attributes": [
                  { "key": "order_id", "value": { "intValue": "42" } },
                  { "key": "paid", "value": { "boolValue": true } }
                ],
                "traceId": "5b8efff798038103d269b633813fc60c",
                "spanId": "eee19b7ec3c1b174"
              }]
            }]
          }]
        }
        JSON;

        $this->assertCount(1, $this->sender->requests);
        $this->assertSame(
            self::sortKeys(json_decode($golden, true)),
            self::sortKeys($this->sender->payload()),
        );
    }

    public function testVersionIsBumped(): void
    {
        $this->assertSame('1.4.0', Logger::VERSION);
        $this->assertSame(ErrorReporter::VERSION, Logger::VERSION);
    }

    public function testNoEnvironmentMeansNoDeploymentAttribute(): void
    {
        $logger = $this->logger(['environment' => null]);
        $logger->info('x');
        $logger->flush();
        $keys = array_column($this->sender->payload()['resourceLogs'][0]['resource']['attributes'], 'key');
        $this->assertNotContains('deployment.environment.name', $keys);
    }

    // 2. Authorization header and endpoint
    public function testHeadersAndEndpoint(): void
    {
        $logger = $this->logger();
        $logger->info('x');
        $logger->flush();

        $request = $this->sender->requests[0];
        $this->assertSame(self::ENDPOINT, $request['url']);
        $this->assertSame('Basic ' . base64_encode(self::PK . ':' . self::SK), $request['headers']['Authorization']);
        $this->assertSame(
            'Basic cGtfMDAxMTIyMzM0NDU1NjY3Nzg4OTlhYWJiY2NkZGVlZmY6c2tfMDAwMTAyMDMwNDA1MDYwNzA4MDkwYTBiMGMwZDBlMGYxMDExMTIxMzE0MTUxNjE3',
            $request['headers']['Authorization'],
        );
        $this->assertSame('application/json', $request['headers']['Content-Type']);
        $this->assertSame('callisto-sdk-php/1.4.0', $request['headers']['User-Agent']);
    }

    public function testDefaultEndpoint(): void
    {
        $logger = $this->logger(['endpoint' => null]);
        $logger->info('x');
        $logger->flush();
        $this->assertSame('https://logs.callistosignal.com/v1/logs', $this->sender->requests[0]['url']);
    }

    // 3. Severity mapping
    /** @return array<string, array{string, int, string}> */
    public static function levels(): array
    {
        return [
            'debug' => ['debug', 5, 'DEBUG'],
            'info' => ['info', 9, 'INFO'],
            'warn' => ['warn', 13, 'WARN'],
            'error' => ['error', 17, 'ERROR'],
            'fatal' => ['fatal', 21, 'FATAL'],
        ];
    }

    #[DataProvider('levels')]
    public function testSeverityViaMethod(string $level, int $number, string $text): void
    {
        $logger = $this->logger();
        $logger->{$level}('x');
        $record = $this->only($logger);
        $this->assertSame($number, $record['severityNumber']);
        $this->assertSame($text, $record['severityText']);
    }

    #[DataProvider('levels')]
    public function testSeverityViaLog(string $level, int $number, string $text): void
    {
        $logger = $this->logger();
        $logger->log(strtoupper($level), 'x');
        $record = $this->only($logger);
        $this->assertSame($number, $record['severityNumber']);
        $this->assertSame($text, $record['severityText']);
    }

    public function testWarningAliasAndUnknownLevel(): void
    {
        $logger = $this->logger();
        $logger->log('warning', 'a');
        $logger->log('nonsense', 'b');
        $logger->flush();
        $records = $this->sender->records();
        $this->assertSame('WARN', $records[0]['severityText']);
        $this->assertSame('INFO', $records[1]['severityText']);
    }

    // 3. AnyValue mapping
    public function testAnyValueMapping(): void
    {
        $logger = $this->logger();
        $logger->info('x', [
            'int' => 42,
            'negative' => -7,
            'float' => 1.5,
            'whole_float' => 2.0,
            'nan' => NAN,
            'inf' => INF,
            'neg_inf' => -INF,
            'str' => 'hi',
            'bool' => false,
            'null' => null,
            'list' => [1, 'a', null, true],
            'map' => ['k' => 'v', 'gone' => null, 'n' => ['deep' => 1]],
            'empty' => [],
            'enum' => LogTestColor::Red,
            'object' => new \ArrayObject([1]),
            7 => 'numeric key',
        ]);
        $attrs = $this->attributes($this->only($logger));

        $this->assertSame(['intValue' => '42'], $attrs['int']);
        $this->assertSame(['intValue' => '-7'], $attrs['negative']);
        $this->assertSame(['doubleValue' => 1.5], $attrs['float']);
        $this->assertSame(['doubleValue' => 2.0], $attrs['whole_float']);
        $this->assertSame(['stringValue' => 'NaN'], $attrs['nan']);
        $this->assertSame(['stringValue' => 'Infinity'], $attrs['inf']);
        $this->assertSame(['stringValue' => '-Infinity'], $attrs['neg_inf']);
        $this->assertSame(['stringValue' => 'hi'], $attrs['str']);
        $this->assertSame(['boolValue' => false], $attrs['bool']);
        $this->assertArrayNotHasKey('null', $attrs);
        $this->assertSame(['arrayValue' => ['values' => [
            ['intValue' => '1'], ['stringValue' => 'a'], [], ['boolValue' => true],
        ]]], $attrs['list']);
        $this->assertSame(['kvlistValue' => ['values' => [
            ['key' => 'k', 'value' => ['stringValue' => 'v']],
            ['key' => 'n', 'value' => ['kvlistValue' => ['values' => [
                ['key' => 'deep', 'value' => ['intValue' => '1']],
            ]]]],
        ]]], $attrs['map']);
        $this->assertSame(['arrayValue' => ['values' => []]], $attrs['empty']);
        $this->assertSame(['stringValue' => 'red'], $attrs['enum']);
        $this->assertArrayHasKey('stringValue', $attrs['object']);
        $this->assertSame(['stringValue' => 'numeric key'], $attrs['7']);
    }

    public function testNullListElementIsAnEmptyObjectOnTheWire(): void
    {
        $logger = $this->logger();
        $logger->info('x', ['list' => [null]]);
        $logger->flush();
        $this->assertStringContainsString('"arrayValue":{"values":[{}]}', $this->sender->requests[0]['body']);
    }

    public function testNestingDeeperThanFiveLevelsIsStringified(): void
    {
        $logger = $this->logger();
        $logger->info('x', ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => 1]]]]]]]);
        $value = $this->attributes($this->only($logger))['a'];
        for ($level = 1; $level <= 5; $level++) {
            $this->assertArrayHasKey('kvlistValue', $value, "level {$level} stays a map");
            $value = $value['kvlistValue']['values'][0]['value'];
        }
        $this->assertSame(['stringValue' => '{"g":1}'], $value);
    }

    public function testMessageIsStringified(): void
    {
        $logger = $this->logger();
        $logger->info(12);
        $logger->info(new class () {
            public function __toString(): string
            {
                return 'stringable';
            }
        });
        $logger->info(['a' => 1]);
        $logger->flush();
        $records = $this->sender->records();
        $this->assertSame('12', $records[0]['body']['stringValue']);
        $this->assertSame('stringable', $records[1]['body']['stringValue']);
        $this->assertSame('{"a":1}', $records[2]['body']['stringValue']);
    }

    public function testEmptyAttributesAreOmitted(): void
    {
        $logger = $this->logger();
        $logger->info('x', ['only_null' => null]);
        $record = $this->only($logger);
        $this->assertArrayNotHasKey('attributes', $record);
        $this->assertArrayNotHasKey('traceId', $record);
        $this->assertArrayNotHasKey('spanId', $record);
    }

    public function testAtMost128Attributes(): void
    {
        $attributes = [];
        for ($i = 0; $i < 200; $i++) {
            $attributes["k{$i}"] = $i;
        }
        $logger = $this->logger();
        $logger->info('x', $attributes);
        $attrs = $this->only($logger)['attributes'];
        $this->assertCount(128, $attrs);
        $this->assertSame('k127', $attrs[127]['key']);
    }

    // 4. Invalid trace_id / span_id stay attributes
    /** @return array<string, array{string, string}> */
    public static function invalidIds(): array
    {
        return [
            'short trace' => ['trace_id', '5b8efff798038103d269b633813fc60'],
            'non-hex trace' => ['trace_id', 'zb8efff798038103d269b633813fc60c'],
            'zero trace' => ['trace_id', '00000000000000000000000000000000'],
            'short span' => ['span_id', 'eee19b7ec3c1b17'],
            'zero span' => ['span_id', '0000000000000000'],
        ];
    }

    #[DataProvider('invalidIds')]
    public function testInvalidIdStaysAnAttribute(string $key, string $value): void
    {
        $logger = $this->logger();
        $logger->info('x', [$key => $value]);
        $record = $this->only($logger);
        $this->assertArrayNotHasKey('traceId', $record);
        $this->assertArrayNotHasKey('spanId', $record);
        $this->assertSame(['stringValue' => $value], $this->attributes($record)[$key]);
    }

    public function testNonStringTraceIdStaysAnAttribute(): void
    {
        $logger = $this->logger();
        $logger->info('x', ['trace_id' => 42]);
        $record = $this->only($logger);
        $this->assertArrayNotHasKey('traceId', $record);
        $this->assertSame(['intValue' => '42'], $this->attributes($record)['trace_id']);
    }

    // 5. Batching
    public function testHundredRecordsTriggerASend(): void
    {
        $logger = $this->logger();
        for ($i = 0; $i < 99; $i++) {
            $logger->info("m{$i}");
        }
        $this->assertCount(0, $this->sender->requests);

        $logger->info('m99');
        $this->assertCount(1, $this->sender->requests);
        $this->assertCount(100, $this->sender->records());

        for ($i = 0; $i < 30; $i++) {
            $logger->info("n{$i}");
        }
        $this->assertCount(1, $this->sender->requests);
        $logger->flush();
        $this->assertCount(2, $this->sender->requests);
        $this->assertCount(30, $this->sender->records());

        $logger->flush();
        $this->assertCount(2, $this->sender->requests, 'nothing left to send');
    }

    public function testBatchIsCutAt500Records(): void
    {
        $logger = $this->logger(['flushAt' => 5000]);
        for ($i = 0; $i < 1200; $i++) {
            $logger->info("m{$i}");
        }
        $logger->flush();

        $this->assertCount(3, $this->sender->requests);
        $this->assertCount(500, $this->sender->records(0));
        $this->assertCount(500, $this->sender->records(1));
        $this->assertCount(200, $this->sender->records(2));
        $this->assertSame('m0', $this->sender->records(0)[0]['body']['stringValue']);
        $this->assertSame('m1199', $this->sender->records(2)[199]['body']['stringValue']);
        $this->assertSame(0, $logger->droppedCount());
    }

    public function testBatchIsCutAtAboutOneMegabyte(): void
    {
        $logger = $this->logger(['flushAt' => 5000]);
        $big = str_repeat('a', 30_000);
        for ($i = 0; $i < 70; $i++) {
            $logger->info($big);
        }
        $logger->flush();

        $this->assertGreaterThan(1, count($this->sender->requests));
        $total = 0;
        foreach ($this->sender->requests as $i => $request) {
            $this->assertLessThan(1_001_000, strlen($request['body']));
            $total += count($this->sender->records($i));
        }
        $this->assertSame(70, $total);
    }

    // 6. Truncation
    public function testBodyAndAttributeTruncation(): void
    {
        $logger = $this->logger();
        $logger->info(str_repeat('b', 40_000), [
            'long' => str_repeat('v', 9_000),
            'nested' => ['inner' => str_repeat('w', 9_000)],
            'accented' => str_repeat('é', 9_000),
            'short' => str_repeat('s', 8_192),
        ]);
        $record = $this->only($logger);
        $attrs = $this->attributes($record);

        $body = $record['body']['stringValue'];
        $this->assertSame(32_768, mb_strlen($body));
        $this->assertStringEndsWith('…', $body);

        $this->assertSame(8_192, mb_strlen($attrs['long']['stringValue']));
        $this->assertStringEndsWith('…', $attrs['long']['stringValue']);
        $inner = $attrs['nested']['kvlistValue']['values'][0]['value']['stringValue'];
        $this->assertSame(8_192, mb_strlen($inner));
        $this->assertSame(str_repeat('é', 8_191) . '…', $attrs['accented']['stringValue']);
        $this->assertSame(str_repeat('s', 8_192), $attrs['short']['stringValue'], 'at the limit: untouched');
    }

    // 7. Responses
    /** @return array<string, array{int}> */
    public static function droppedStatuses(): array
    {
        return [
            '400' => [400], '413' => [413], '415' => [415], '429' => [429],
            '500' => [500], '503' => [503], 'network error' => [0],
        ];
    }

    #[DataProvider('droppedStatuses')]
    public function testFailedBatchIsDroppedWithoutRetry(int $status): void
    {
        $this->sender->statuses = [$status];
        $logger = $this->logger();
        $logger->info('a');
        $logger->info('b');
        $logger->flush();
        $this->assertCount(1, $this->sender->requests);
        $this->assertSame(2, $logger->droppedCount());

        $logger->flush();
        $this->assertCount(1, $this->sender->requests, 'no retry');

        // Not paused: the next record goes out normally.
        $logger->info('c');
        $logger->flush();
        $this->assertCount(2, $this->sender->requests);
    }

    /** @return array<string, array{int}> */
    public static function pauseStatuses(): array
    {
        return ['401' => [401], '403' => [403]];
    }

    #[DataProvider('pauseStatuses')]
    public function testAuthFailurePausesSixtySeconds(int $status): void
    {
        $this->sender->statuses = [$status];
        $logger = $this->logger(['flushAt' => 5000]);
        for ($i = 0; $i < 600; $i++) {
            $logger->info("m{$i}");
        }
        $logger->flush();
        $this->assertCount(1, $this->sender->requests, 'the rest of the queue is dropped, not sent');
        $this->assertSame(600, $logger->droppedCount());

        $this->now += 59 * 1_000_000_000;
        $logger->info('during pause');
        $logger->flush();
        $this->assertCount(1, $this->sender->requests);
        $this->assertSame(601, $logger->droppedCount());

        $this->now += 2 * 1_000_000_000;
        $logger->info('after pause');
        $logger->flush();
        $this->assertCount(2, $this->sender->requests);
        $this->assertSame('after pause', $this->sender->records()[0]['body']['stringValue']);
    }

    // 8. Full queue
    public function testFullQueueDropsAndCounts(): void
    {
        $logger = $this->logger(['flushAt' => 5000]);
        for ($i = 0; $i < 2010; $i++) {
            $logger->info("m{$i}");
        }
        $this->assertSame(10, $logger->droppedCount());
        $logger->flush();

        $sent = 0;
        foreach ($this->sender->requests as $i => $_) {
            $sent += count($this->sender->records($i));
        }
        $this->assertSame(2000, $sent);
    }

    public function testMaxQueueIsConfigurable(): void
    {
        $logger = $this->logger(['maxQueue' => 3]);
        for ($i = 0; $i < 5; $i++) {
            $logger->info("m{$i}");
        }
        $this->assertSame(2, $logger->droppedCount());
        $logger->flush();
        $this->assertCount(3, $this->sender->records());
    }

    // 9. Disabled logger
    public function testDisabledLoggerSendsNothing(): void
    {
        $logger = new Logger(sender: $this->sender);
        $this->assertFalse($logger->isEnabled());
        for ($i = 0; $i < 150; $i++) {
            $logger->error('x', ['a' => 1]);
        }
        $logger->flush();
        $logger->close();
        $this->assertSame([], $this->sender->requests);
        $this->assertSame(0, $logger->droppedCount());
    }

    public function testEmptyStringsMeanDisabled(): void
    {
        $logger = new Logger(accessKey: '', secret: '', sender: $this->sender);
        $this->assertFalse($logger->isEnabled());
    }

    // 10. Half or malformed credentials
    /** @return array<string, array{array<string, string|null>}> */
    public static function badConfig(): array
    {
        return [
            'key only' => [['accessKey' => self::PK, 'secret' => null]],
            'secret only' => [['accessKey' => null, 'secret' => self::SK]],
            'uppercase key' => [['accessKey' => strtoupper(self::PK)]],
            'messaging key as key' => [['accessKey' => 'cid-1234']],
            'short key' => [['accessKey' => 'pk_0011']],
            'secret with pk prefix' => [['secret' => 'pk_000102030405060708090a0b0c0d0e0f1011121314151617']],
            'short secret' => [['secret' => 'sk_00']],
            'relative endpoint' => [['endpoint' => '/v1/logs']],
            'ftp endpoint' => [['endpoint' => 'ftp://logs.test/v1/logs']],
            'not a url' => [['endpoint' => 'logs']],
        ];
    }

    #[DataProvider('badConfig')]
    public function testBadConfigurationFailsFast(array $options): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->logger($options);
    }

    public function testConfigErrorNeverEchoesTheSecret(): void
    {
        $bad = 'sk_THIS-IS-SECRET';
        try {
            $this->logger(['secret' => $bad]);
            $this->fail('expected a configuration error');
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString($bad, $e->getMessage());
        }
    }

    public function testHalfCredentialsFromEnvFailFast(): void
    {
        putenv('CALLISTO_LOGS_ACCESS_KEY=' . self::PK);
        $this->expectException(InvalidArgumentException::class);
        new Logger(sender: $this->sender);
    }

    public function testClientWithHalfLogsCredentialsFailsFast(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Client(clientId: 'cid', apiKey: 'key', logsAccessKey: self::PK);
    }

    // Environment resolution
    public function testConfigurationFromEnvironment(): void
    {
        putenv('CALLISTO_LOGS_ACCESS_KEY=' . self::PK);
        putenv('CALLISTO_LOGS_SECRET=' . self::SK);
        putenv('CALLISTO_LOGS_ENDPOINT=http://localhost:4318/v1/logs');
        putenv('OTEL_SERVICE_NAME=otel-name');
        putenv('CALLISTO_ENVIRONMENT=staging');

        $logger = new Logger(sender: $this->sender, clock: fn (): int => $this->now);
        $this->assertTrue($logger->isEnabled());
        $logger->info('x');
        $logger->flush();

        $this->assertSame('http://localhost:4318/v1/logs', $this->sender->requests[0]['url']);
        $resource = [];
        foreach ($this->sender->payload()['resourceLogs'][0]['resource']['attributes'] as $kv) {
            $resource[$kv['key']] = $kv['value']['stringValue'];
        }
        $this->assertSame('otel-name', $resource['service.name']);
        $this->assertSame('staging', $resource['deployment.environment.name']);

        putenv('CALLISTO_SERVICE_NAME=callisto-name');
        $logger = new Logger(sender: $this->sender);
        $logger->info('x');
        $logger->flush();
        $this->assertSame(
            'callisto-name',
            $this->sender->payload()['resourceLogs'][0]['resource']['attributes'][0]['value']['stringValue'],
        );
    }

    public function testDefaultServiceName(): void
    {
        $logger = $this->logger(['serviceName' => null]);
        $logger->info('x');
        $logger->flush();
        $this->assertSame(
            'unknown_service',
            $this->sender->payload()['resourceLogs'][0]['resource']['attributes'][0]['value']['stringValue'],
        );
    }

    public function testArgumentsWinOverEnvironment(): void
    {
        putenv('CALLISTO_LOGS_ACCESS_KEY=pk_ffffffffffffffffffffffffffffffff');
        putenv('CALLISTO_SERVICE_NAME=from-env');
        $logger = $this->logger();
        $logger->info('x');
        $logger->flush();
        $this->assertSame(
            'Basic ' . base64_encode(self::PK . ':' . self::SK),
            $this->sender->requests[0]['headers']['Authorization'],
        );
        $this->assertSame(
            'checkout',
            $this->sender->payload()['resourceLogs'][0]['resource']['attributes'][0]['value']['stringValue'],
        );
    }

    // Never throws, never recurses
    public function testSenderExceptionNeverEscapes(): void
    {
        $this->sender->statuses = [0, 0];
        $logger = $this->logger(['flushAt' => 1]);
        $logger->info('a');
        $logger->flush();
        $logger->close();
        $this->assertSame(1, $logger->droppedCount());
    }

    public function testLogsEmittedWhileSendingAreDropped(): void
    {
        $logger = $this->logger();
        $this->sender->during = static function () use ($logger): void {
            $logger->info('from inside the send');
        };
        $logger->info('a');
        $logger->flush();

        $this->sender->during = null;
        $logger->flush();
        $this->assertCount(1, $this->sender->requests, 'the inner record was not re-shipped');
        $this->assertSame(1, $logger->droppedCount());
    }

    public function testCloseFlushesThenStops(): void
    {
        $logger = $this->logger();
        $logger->info('a');
        $logger->close();
        $this->assertCount(1, $this->sender->requests);

        $logger->info('b');
        $logger->flush();
        $this->assertCount(1, $this->sender->requests);
    }

    public function testFlushTimeoutDropsWhatIsLeft(): void
    {
        $logger = $this->logger(['flushAt' => 5000, 'flushTimeout' => 0.0]);
        for ($i = 0; $i < 1200; $i++) {
            $logger->info("m{$i}");
        }
        $this->sender->during = static function (): void {
            usleep(1000);
        };
        $logger->flush();
        $this->assertCount(1, $this->sender->requests);
        $this->assertSame(700, $logger->droppedCount());
    }

    // 11. Isolation from the messaging credentials
    public function testCredentialsNeverCrossPaths(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['status' => 'sent'])),
        ]));
        $stack->push(Middleware::history($history));

        $client = new Client(
            clientId: 'cid-messaging-7f3a',
            apiKey: 'apikey-messaging-9b2c',
            baseUrl: 'https://api.test/v1',
            httpClient: new GuzzleClient(['handler' => $stack]),
            logsAccessKey: self::PK,
            logsSecret: self::SK,
            logsEndpoint: self::ENDPOINT,
            logsSender: $this->sender,
        );
        $this->assertTrue($client->logs->isEnabled());
        $this->assertSame($client->logs, $client->logs());

        $client->sms()->send(sender: 'Acme', to: '+2250700000000', message: 'Hi');
        $client->logs->info('order paid', ['order_id' => 42]);
        $client->flush();

        $this->assertCount(1, $this->sender->requests);
        $logsRequest = json_encode($this->sender->requests[0]);
        foreach (['cid-messaging-7f3a', 'apikey-messaging-9b2c', base64_encode('cid-messaging-7f3a:apikey-messaging-9b2c')] as $secret) {
            $this->assertStringNotContainsString($secret, $logsRequest);
        }

        $this->assertCount(1, $history);
        $messaging = $history[0]['request'];
        $raw = json_encode($messaging->getHeaders()) . (string) $messaging->getBody() . (string) $messaging->getUri();
        foreach ([self::SK, self::PK, base64_encode(self::PK . ':' . self::SK)] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
        $this->assertSame('api.test', $messaging->getUri()->getHost());
    }

    public function testClientLogsIsANoOpWithoutALogsKey(): void
    {
        $client = new Client(clientId: 'cid', apiKey: 'key', logsSender: $this->sender);
        $this->assertFalse($client->logs->isEnabled());
        $client->logs->info('x');
        $client->flush();
        $client->close();
        $this->assertSame([], $this->sender->requests);
    }

    public function testClientCloseClosesTheLogger(): void
    {
        $client = new Client(
            clientId: 'cid',
            apiKey: 'key',
            logsAccessKey: self::PK,
            logsSecret: self::SK,
            environment: 'production',
            serviceName: 'billing',
            logsSender: $this->sender,
        );
        $client->logs->warn('x');
        $client->close();
        $client->logs->warn('y');
        $client->flush();

        $this->assertCount(1, $this->sender->requests);
        $resource = $this->sender->payload()['resourceLogs'][0]['resource']['attributes'];
        $this->assertSame('billing', $resource[0]['value']['stringValue']);
        $this->assertSame('production', $resource[1]['value']['stringValue']);
    }

    // The default HTTP path
    public function testGuzzleSenderPostsWithShortTimeouts(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200), new Response(401)]));
        $stack->push(Middleware::history($history));
        $sender = new GuzzleSender(new GuzzleClient(['handler' => $stack]));

        $headers = ['Authorization' => 'Basic abc', 'Content-Type' => 'application/json', 'User-Agent' => 'ua/1'];
        $this->assertSame(200, $sender->send(self::ENDPOINT, $headers, '{"a":1}'));
        $this->assertSame(401, $sender->send(self::ENDPOINT, $headers, '{}'), 'non-2xx is returned, not thrown');

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(self::ENDPOINT, (string) $request->getUri());
        $this->assertSame('Basic abc', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('ua/1', $request->getHeaderLine('User-Agent'));
        $this->assertSame('{"a":1}', (string) $request->getBody());
        $this->assertSame(2.0, $history[0]['options']['connect_timeout']);
        $this->assertSame(3.0, $history[0]['options']['timeout']);
    }
}

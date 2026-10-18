<?php

declare(strict_types=1);

namespace Callisto\Sdk\Logs;

use Callisto\Sdk\ErrorReporter;
use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * Ships the application's own log lines to callisto-logs (OTLP/HTTP, JSON).
 *
 * Works on its own (no messaging credentials needed) and is also exposed as
 * `$client->logs` on {@see \Callisto\Sdk\Client}.
 *
 * Delivery in PHP is synchronous (a documented exception to the other SDKs):
 * PHP has no portable background thread in a request. Records are queued in
 * memory and a batch is POSTed when {@see $flushAt} records are queued (100 by
 * default), on {@see flush()}, and from a shutdown function registered once
 * per logger. Each request has a 2 s connect / 3 s total timeout and is never
 * retried: a failed batch is dropped. After a 401 or 403 sending pauses 60 s.
 *
 * A log call never throws. The logger's own failures are swallowed: they are
 * never reported to the error reporter and never logged through the logger.
 *
 * With no Logs key at all the logger is disabled and every call is a cheap
 * no-op. Half or malformed credentials fail fast at construction with an
 * {@see InvalidArgumentException}, the SDK's configuration error.
 */
final class Logger
{
    public const VERSION = ErrorReporter::VERSION;
    public const SDK_NAME = 'callisto-sdk';
    public const LANGUAGE = 'php';
    public const USER_AGENT = 'callisto-sdk-php/' . self::VERSION;
    public const DEFAULT_ENDPOINT = 'https://logs.callistosignal.com/v1/logs';
    public const DEFAULT_SERVICE_NAME = 'unknown_service';

    public const MAX_QUEUE = 2000;
    public const FLUSH_AT = 100;
    public const MAX_BATCH_RECORDS = 500;
    public const MAX_BATCH_BYTES = 1_000_000;
    public const MAX_BODY_CHARS = 32_768;
    public const MAX_ATTRIBUTE_CHARS = 8_192;
    public const MAX_ATTRIBUTES = 128;
    public const MAX_DEPTH = 5;
    public const PAUSE_SECONDS = 60;
    public const FLUSH_TIMEOUT = 5.0;

    private const ACCESS_KEY_PATTERN = '/^pk_[0-9a-f]{32}$/';
    private const SECRET_PATTERN = '/^sk_[0-9a-f]{48}$/';

    /** level => [severityNumber, severityText] */
    private const SEVERITIES = [
        'debug' => [5, 'DEBUG'],
        'info' => [9, 'INFO'],
        'warn' => [13, 'WARN'],
        'error' => [17, 'ERROR'],
        'fatal' => [21, 'FATAL'],
    ];

    private const LEVEL_ALIASES = [
        'trace' => 'debug',
        'warning' => 'warn',
        'critical' => 'error',
    ];

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE;

    private readonly bool $enabled;
    private readonly string $endpoint;
    private readonly string $serviceName;
    private readonly ?string $environment;
    private readonly Sender $sender;
    private readonly Closure $clock;
    private readonly int $maxQueue;
    private readonly int $flushAt;
    private readonly float $flushTimeout;

    /** @var array<string, string> */
    private array $headers = [];

    /** JSON of everything before the records: resource + scope. */
    private string $envelopeHead = '';

    /** @var list<string> each queued record, already JSON-encoded */
    private array $queue = [];

    private int $dropped = 0;
    private int $pausedUntilNs = 0;
    private bool $sending = false;
    private bool $closed = false;
    private bool $shutdownRegistered = false;

    /**
     * Every argument is optional and falls back to the environment:
     * CALLISTO_LOGS_ACCESS_KEY, CALLISTO_LOGS_SECRET, CALLISTO_LOGS_ENDPOINT,
     * CALLISTO_SERVICE_NAME then OTEL_SERVICE_NAME, CALLISTO_ENVIRONMENT.
     *
     * @param Sender|null $sender HTTP path for a batch; defaults to {@see GuzzleSender}
     * @param Closure|null $clock `fn (): int` returning the Unix time in nanoseconds
     * @param int $maxQueue records held in memory before new ones are dropped
     * @param int $flushAt queued records that trigger a synchronous send
     * @param float $flushTimeout seconds one flush may spend sending before it drops the rest
     *
     * @throws InvalidArgumentException on half or malformed credentials, or a bad endpoint
     */
    public function __construct(
        ?string $accessKey = null,
        ?string $secret = null,
        ?string $endpoint = null,
        ?string $serviceName = null,
        ?string $environment = null,
        ?Sender $sender = null,
        ?Closure $clock = null,
        int $maxQueue = self::MAX_QUEUE,
        int $flushAt = self::FLUSH_AT,
        float $flushTimeout = self::FLUSH_TIMEOUT,
    ) {
        $accessKey = ($accessKey ?? (getenv('CALLISTO_LOGS_ACCESS_KEY') ?: null)) ?: null;
        $secret = ($secret ?? (getenv('CALLISTO_LOGS_SECRET') ?: null)) ?: null;
        $endpoint = ($endpoint ?? (getenv('CALLISTO_LOGS_ENDPOINT') ?: null)) ?: self::DEFAULT_ENDPOINT;
        $this->serviceName = ($serviceName
            ?? (getenv('CALLISTO_SERVICE_NAME') ?: null)
            ?? (getenv('OTEL_SERVICE_NAME') ?: null)) ?: self::DEFAULT_SERVICE_NAME;
        $this->environment = ($environment ?? (getenv('CALLISTO_ENVIRONMENT') ?: null)) ?: null;

        if (($accessKey === null) !== ($secret === null)) {
            throw new InvalidArgumentException(
                'Callisto: logsAccessKey and logsSecret must be set together '
                . '(pass both arguments or set CALLISTO_LOGS_ACCESS_KEY / CALLISTO_LOGS_SECRET).'
            );
        }
        if ($accessKey !== null && preg_match(self::ACCESS_KEY_PATTERN, $accessKey) !== 1) {
            throw new InvalidArgumentException(
                'Callisto: logsAccessKey must be "pk_" followed by 32 lowercase hex characters (a Logs API key).'
            );
        }
        if ($secret !== null && preg_match(self::SECRET_PATTERN, $secret) !== 1) {
            throw new InvalidArgumentException(
                'Callisto: logsSecret must be "sk_" followed by 48 lowercase hex characters (a Logs API key).'
            );
        }
        if (!self::isHttpUrl($endpoint)) {
            throw new InvalidArgumentException('Callisto: logsEndpoint must be an absolute http(s) URL.');
        }

        $this->enabled = $accessKey !== null;
        $this->endpoint = $endpoint;
        $this->sender = $sender ?? new GuzzleSender();
        $this->clock = $clock ?? static function (): int {
            $now = gettimeofday();

            return $now['sec'] * 1_000_000_000 + $now['usec'] * 1_000;
        };
        $this->maxQueue = max(1, $maxQueue);
        $this->flushAt = max(1, $flushAt);
        $this->flushTimeout = max(0.0, $flushTimeout);

        if ($this->enabled) {
            $this->headers = [
                'Authorization' => 'Basic ' . base64_encode($accessKey . ':' . $secret),
                'Content-Type' => 'application/json',
                'User-Agent' => self::USER_AGENT,
            ];
            $this->envelopeHead = $this->buildEnvelopeHead();
        }
    }

    /** Whether a Logs key resolved (false: every call is a no-op). */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Records lost so far: queue full, sending paused after 401/403, a batch
     * the ingest refused or could not be reached for, or a flush that ran out
     * of time. Read-only, for tests and diagnostics.
     */
    public function droppedCount(): int
    {
        return $this->dropped;
    }

    /** @param array<array-key, mixed> $attributes */
    public function debug(mixed $message, array $attributes = []): void
    {
        $this->log('debug', $message, $attributes);
    }

    /** @param array<array-key, mixed> $attributes */
    public function info(mixed $message, array $attributes = []): void
    {
        $this->log('info', $message, $attributes);
    }

    /** @param array<array-key, mixed> $attributes */
    public function warn(mixed $message, array $attributes = []): void
    {
        $this->log('warn', $message, $attributes);
    }

    /** @param array<array-key, mixed> $attributes */
    public function error(mixed $message, array $attributes = []): void
    {
        $this->log('error', $message, $attributes);
    }

    /** @param array<array-key, mixed> $attributes */
    public function fatal(mixed $message, array $attributes = []): void
    {
        $this->log('fatal', $message, $attributes);
    }

    /**
     * Queue one record. `level` is debug|info|warn|error|fatal (case-insensitive;
     * `warning` is accepted for warn, anything unknown is logged as info).
     *
     * `trace_id` (32 hex) and `span_id` (16 hex), when valid and not all zeros,
     * are moved to the record's traceId / spanId; otherwise they stay attributes.
     *
     * @param array<array-key, mixed> $attributes
     */
    public function log(string $level, mixed $message, array $attributes = []): void
    {
        if (!$this->enabled || $this->closed) {
            return;
        }
        if ($this->sending) {
            // Emitted from inside our own send (an HTTP middleware, a PSR-3
            // adapter wired into Guzzle...): never re-ship it.
            $this->dropped++;

            return;
        }

        try {
            if ($this->isPaused()) {
                $this->dropped++;

                return;
            }
            if (count($this->queue) >= $this->maxQueue) {
                $this->dropped++;

                return;
            }

            $this->queue[] = $this->encodeRecord($level, $message, $attributes);
            $this->registerShutdown();

            if (count($this->queue) >= $this->flushAt) {
                $this->drain();
            }
        } catch (Throwable) {
            // A log call never throws.
        }
    }

    /**
     * Send everything queued now, in batches of at most 500 records / ~1 MB.
     * Bounded by the flush timeout; never throws.
     */
    public function flush(): void
    {
        if (!$this->enabled || $this->closed) {
            return;
        }

        try {
            $this->drain();
        } catch (Throwable) {
            // swallow
        }
    }

    /** Flush, then stop: later calls are no-ops. */
    public function close(): void
    {
        $this->flush();
        $this->closed = true;
        $this->queue = [];
    }

    private function isPaused(): bool
    {
        return $this->pausedUntilNs > 0 && ($this->clock)() < $this->pausedUntilNs;
    }

    private function registerShutdown(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function(function (): void {
            $this->flush();
        });
    }

    private function drain(): void
    {
        if ($this->sending || $this->queue === []) {
            return;
        }
        $this->sending = true;

        try {
            $deadline = hrtime(true) + (int) ($this->flushTimeout * 1_000_000_000);
            $first = true;
            while ($this->queue !== []) {
                // The first batch always goes; later ones only within the timeout.
                if ($this->isPaused() || (!$first && hrtime(true) > $deadline)) {
                    $this->dropped += count($this->queue);
                    $this->queue = [];
                    break;
                }
                $first = false;
                $this->sendBatch($this->takeBatch());
            }
        } finally {
            $this->sending = false;
        }
    }

    /** @return list<string> */
    private function takeBatch(): array
    {
        $count = 0;
        $bytes = 0;
        foreach ($this->queue as $record) {
            $size = strlen($record) + 1;
            if ($count >= self::MAX_BATCH_RECORDS || ($count > 0 && $bytes + $size > self::MAX_BATCH_BYTES)) {
                break;
            }
            $count++;
            $bytes += $size;
        }

        return array_splice($this->queue, 0, max(1, $count));
    }

    /** @param list<string> $records */
    private function sendBatch(array $records): void
    {
        $body = $this->envelopeHead . implode(',', $records) . ']}]}]}';

        try {
            $status = $this->sender->send($this->endpoint, $this->headers, $body);
        } catch (Throwable) {
            // Network error or timeout: no retry in PHP, the batch is dropped.
            $this->dropped += count($records);

            return;
        }

        if ($status >= 200 && $status < 300) {
            return;
        }
        if ($status === 401 || $status === 403) {
            $this->pausedUntilNs = ($this->clock)() + self::PAUSE_SECONDS * 1_000_000_000;
        }
        // 400/413/415, 401/403, 429/503 (no retry here) and anything else.
        $this->dropped += count($records);
    }

    private function buildEnvelopeHead(): string
    {
        $resource = [['key' => 'service.name', 'value' => ['stringValue' => $this->serviceName]]];
        if ($this->environment !== null) {
            $resource[] = ['key' => 'deployment.environment.name', 'value' => ['stringValue' => $this->environment]];
        }
        $resource[] = ['key' => 'telemetry.sdk.name', 'value' => ['stringValue' => self::SDK_NAME]];
        $resource[] = ['key' => 'telemetry.sdk.language', 'value' => ['stringValue' => self::LANGUAGE]];
        $resource[] = ['key' => 'telemetry.sdk.version', 'value' => ['stringValue' => self::VERSION]];

        $scope = ['name' => self::SDK_NAME, 'version' => self::VERSION];

        return '{"resourceLogs":[{"resource":'
            . json_encode(['attributes' => $resource], self::JSON_FLAGS | JSON_THROW_ON_ERROR)
            . ',"scopeLogs":[{"scope":'
            . json_encode($scope, self::JSON_FLAGS | JSON_THROW_ON_ERROR)
            . ',"logRecords":[';
    }

    /** @param array<array-key, mixed> $attributes */
    private function encodeRecord(string $level, mixed $message, array $attributes): string
    {
        [$severityNumber, $severityText] = self::SEVERITIES[self::normalizeLevel($level)];
        $now = (string) ($this->clock)();

        $traceId = self::extractId($attributes, 'trace_id', 32);
        $spanId = self::extractId($attributes, 'span_id', 16);

        $record = [
            'timeUnixNano' => $now,
            'observedTimeUnixNano' => $now,
            'severityNumber' => $severityNumber,
            'severityText' => $severityText,
            'body' => ['stringValue' => self::truncate(self::stringify($message), self::MAX_BODY_CHARS)],
        ];

        $kv = self::keyValues($attributes, 1, self::MAX_ATTRIBUTES);
        if ($kv !== []) {
            $record['attributes'] = $kv;
        }
        if ($traceId !== null) {
            $record['traceId'] = $traceId;
        }
        if ($spanId !== null) {
            $record['spanId'] = $spanId;
        }

        return json_encode($record, self::JSON_FLAGS | JSON_THROW_ON_ERROR);
    }

    private static function normalizeLevel(string $level): string
    {
        $level = strtolower($level);
        $level = self::LEVEL_ALIASES[$level] ?? $level;

        return isset(self::SEVERITIES[$level]) ? $level : 'info';
    }

    /**
     * Remove and return a valid, lowercased trace/span id from the attributes;
     * an invalid one is left in place as an ordinary attribute.
     *
     * @param array<array-key, mixed> $attributes
     */
    private static function extractId(array &$attributes, string $key, int $length): ?string
    {
        $value = $attributes[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }
        if (preg_match('/^[0-9a-fA-F]{' . $length . '}$/', $value) !== 1 || trim($value, '0') === '') {
            return null;
        }
        unset($attributes[$key]);

        return strtolower($value);
    }

    /**
     * @param array<array-key, mixed> $map
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    private static function keyValues(array $map, int $depth, ?int $limit = null): array
    {
        $out = [];
        foreach ($map as $key => $value) {
            if ($limit !== null && count($out) >= $limit) {
                break;
            }
            $any = self::anyValue($value, $depth);
            if ($any === null) {
                continue;
            }
            $out[] = ['key' => (string) $key, 'value' => $any];
        }

        return $out;
    }

    /**
     * Map a PHP value to an OTLP AnyValue; null means "omit".
     *
     * @return array<string, mixed>|null
     */
    private static function anyValue(mixed $value, int $depth): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return ['boolValue' => $value];
        }
        if (is_int($value)) {
            return ['intValue' => (string) $value];
        }
        if (is_float($value)) {
            if (is_nan($value)) {
                return ['stringValue' => 'NaN'];
            }
            if (is_infinite($value)) {
                return ['stringValue' => $value > 0 ? 'Infinity' : '-Infinity'];
            }

            return ['doubleValue' => $value];
        }
        if (is_string($value)) {
            return ['stringValue' => self::truncate($value, self::MAX_ATTRIBUTE_CHARS)];
        }
        if ($value instanceof \BackedEnum) {
            return self::anyValue($value->value, $depth);
        }
        if (is_array($value) && $depth <= self::MAX_DEPTH) {
            if (array_is_list($value)) {
                $values = [];
                foreach ($value as $item) {
                    $values[] = self::anyValue($item, $depth + 1) ?? new \stdClass();
                }

                return ['arrayValue' => ['values' => $values]];
            }

            return ['kvlistValue' => ['values' => self::keyValues($value, $depth + 1)]];
        }
        if ($value instanceof \JsonSerializable && $depth <= self::MAX_DEPTH) {
            try {
                return self::anyValue($value->jsonSerialize(), $depth + 1);
            } catch (Throwable) {
                // fall through to stringify
            }
        }

        // Too deep, or a value with no AnyValue of its own: stringified.
        return ['stringValue' => self::truncate(self::stringify($value), self::MAX_ATTRIBUTE_CHARS)];
    }

    private static function stringify(mixed $value): string
    {
        try {
            return match (true) {
                is_string($value) => $value,
                $value === null => '',
                is_bool($value) => $value ? 'true' : 'false',
                is_float($value) && is_nan($value) => 'NaN',
                is_float($value) && is_infinite($value) => $value > 0 ? 'Infinity' : '-Infinity',
                is_int($value), is_float($value) => (string) $value,
                $value instanceof \UnitEnum => $value instanceof \BackedEnum ? (string) $value->value : $value->name,
                $value instanceof Throwable => $value::class . ': ' . $value->getMessage(),
                $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::RFC3339_EXTENDED),
                $value instanceof \Stringable => (string) $value,
                is_array($value), is_object($value) => (string) (json_encode(
                    $value,
                    self::JSON_FLAGS | JSON_PARTIAL_OUTPUT_ON_ERROR
                ) ?: get_debug_type($value)),
                default => get_debug_type($value),
            };
        } catch (Throwable) {
            return get_debug_type($value);
        }
    }

    /** Cut to at most $limit characters, the last one being "…". */
    private static function truncate(string $value, int $limit): string
    {
        if (strlen($value) <= $limit) {
            return $value; // byte length bounds the character length
        }
        $value = mb_scrub($value, 'UTF-8');
        if (mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit - 1, 'UTF-8') . '…';
    }

    private static function isHttpUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return false;
        }

        return in_array(strtolower($parts['scheme']), ['http', 'https'], true);
    }
}

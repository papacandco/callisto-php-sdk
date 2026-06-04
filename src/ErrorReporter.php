<?php

declare(strict_types=1);

namespace Callisto\Sdk;

use Callisto\Sdk\Error\GuzzleSender;
use Callisto\Sdk\Error\Sender;
use Callisto\Sdk\Exception\CallistoException;
use Callisto\Sdk\Exception\RateLimitException;
use Throwable;

/**
 * Opt-in, Sentry-style error reporter. POSTs captured errors to the Callisto
 * error-tracking ingest endpoint (the DSN).
 *
 * Delivery in PHP is synchronous best-effort with a short timeout — PHP has no
 * portable background threads in a request context. Every failure (any
 * exception, any non-202) is swallowed; the reporter NEVER re-captures its own
 * failures and NEVER throws.
 *
 * When the DSN is absent or not a well-formed URL, every method is a cheap no-op.
 *
 * PII rule (hard): this reporter NEVER transmits clientId / apiKey / the
 * Authorization header / the outgoing request body.
 */
final class ErrorReporter
{
    public const NAME = 'callisto/sdk';
    public const VERSION = '1.0.0';
    public const LANGUAGE = 'php';

    private const LEVELS = ['fatal', 'error', 'warning', 'info'];

    /** Source lines captured on each side of a frame's error line. */
    private const CONTEXT_LINES = 5;

    /** Skip source capture for files larger than this (bytes). */
    private const MAX_SOURCE_BYTES = 2_000_000;

    private readonly ?string $dsn;
    private readonly Sender $sender;

    /** @var array<string, mixed>|null */
    private ?array $user = null;

    public function __construct(
        ?string $dsn,
        private readonly ?string $environment = null,
        ?Sender $sender = null,
    ) {
        $this->dsn = self::isValidDsn($dsn) ? $dsn : null;
        $this->sender = $sender ?? new GuzzleSender();
    }

    public function isEnabled(): bool
    {
        return $this->dsn !== null;
    }

    /**
     * Capture an exception/throwable and deliver it (best-effort).
     *
     * @param array<string, mixed>|null $extra  merged into context
     * @param array{method:string,path:string}|null $request  inbound request (method/path)
     * @param bool $withSource  whether to attach the source window to frames.
     *        Defaults true; the SDK's own Transport passes false because a
     *        transport call site can embed the outgoing request body as literal
     *        arguments (see buildExceptionPayload). Framework integrations leave
     *        it true: their requests carry only method/path, never a body.
     */
    public function captureException(
        Throwable $e,
        string $level = 'error',
        ?array $extra = null,
        ?array $request = null,
        bool $withSource = true,
    ): void {
        if ($this->dsn === null) {
            return;
        }

        try {
            $payload = $this->buildExceptionPayload($e, $level, $extra, $request, $withSource);
            $this->dispatch($payload);
        } catch (Throwable) {
            // Never re-capture our own failures, never throw.
        }
    }

    /**
     * Capture a plain message.
     *
     * @param array<string, mixed>|null $extra
     */
    public function captureMessage(string $message, string $level = 'info', ?array $extra = null): void
    {
        if ($this->dsn === null) {
            return;
        }

        try {
            $payload = [
                'message' => $message,
                'type' => 'message',
                'level' => $this->normalizeLevel($level),
                'context' => $this->buildContext($extra),
            ];
            if ($this->user !== null) {
                $payload['user'] = $this->user;
            }
            $this->dispatch($payload);
        } catch (Throwable) {
            // swallow
        }
    }

    /**
     * Set or clear the user context attached to subsequent events.
     *
     * @param array<string, mixed>|null $user
     */
    public function setUser(?array $user): void
    {
        $this->user = $user;
    }

    /**
     * No-op / best-effort for the synchronous PHP implementation: there is no
     * background queue to drain.
     */
    public function flush(): void
    {
        // Synchronous delivery means there is nothing pending.
    }

    /**
     * @param array<string, mixed>|null $extra
     * @param array{method:string,path:string}|null $request
     * @return array<string, mixed>
     */
    private function buildExceptionPayload(
        Throwable $e,
        string $level,
        ?array $extra,
        ?array $request,
        bool $withSource,
    ): array {
        $context = $this->buildContext($extra);

        if ($e instanceof CallistoException) {
            $status = $e->getStatusCode();
            if ($status !== 0) {
                $context['status_code'] = $status;
            }
            $body = $e->getBody();
            if ($body !== null) {
                $context['body'] = $body;
            }
        }
        if ($e instanceof RateLimitException && $e->getRetryAfter() !== null) {
            $context['retry_after'] = $e->getRetryAfter();
        }

        $payload = [
            'message' => $e->getMessage(),
            'type' => $e::class,
            'level' => $this->normalizeLevel($level),
            'culprit' => $this->culprit($e, $request),
            'context' => $context,
        ];

        // Source context is captured unless the caller opts out. The SDK's own
        // Transport opts out ($withSource = false) because a transport call site
        // can embed the outgoing request body as literal arguments, and reading
        // it would violate the hard no-request-body guarantee. Framework-caught
        // application exceptions keep it on — their requests carry only
        // method/path — so the failing code is shown with the error line in focus.
        $stacktrace = $this->stacktrace($e, $withSource);
        if ($stacktrace !== []) {
            $payload['stacktrace'] = $stacktrace;
        }

        if ($request !== null) {
            $payload['request'] = [
                'method' => $request['method'],
                'path' => $request['path'],
            ];
        }

        if ($this->user !== null) {
            $payload['user'] = $this->user;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed>|null $extra
     * @return array<string, mixed>
     */
    private function buildContext(?array $extra): array
    {
        $context = [
            'sdk' => [
                'name' => self::NAME,
                'version' => self::VERSION,
                'language' => self::LANGUAGE,
            ],
        ];
        if ($this->environment !== null) {
            $context['environment'] = $this->environment;
        }
        if ($extra !== null) {
            foreach ($extra as $key => $value) {
                $context[$key] = $value;
            }
        }

        return $context;
    }

    /**
     * @param array{method:string,path:string}|null $request
     */
    private function culprit(Throwable $e, ?array $request): string
    {
        if ($request !== null) {
            return $request['method'] . ' ' . $request['path'];
        }

        $frame = $e->getTrace()[0] ?? null;
        if (is_array($frame) && isset($frame['function'])) {
            $where = '';
            if (isset($e->getTrace()[0]['file'], $e->getTrace()[0]['line'])) {
                $where = ' (' . $frame['file'] . ':' . $frame['line'] . ')';
            }

            return (string) $frame['function'] . $where;
        }

        return $e->getFile() . ':' . $e->getLine();
    }

    /**
     * Build the frame list. Each frame carries function / file / line, and —
     * when the source file is readable — a Sentry-style source window around the
     * error line (`pre_context`, `context_line`, `post_context`, up to
     * self::CONTEXT_LINES each side) so the dashboard can render the failing
     * code with the error line in focus.
     *
     * @param bool $withSource Whether to attach the source window (off for
     *                         transport errors — see buildExceptionPayload).
     * @return array<int, array{function:?string,file:?string,line:?int,pre_context?:list<string>,context_line?:string,post_context?:list<string>}>
     */
    private function stacktrace(Throwable $e, bool $withSource): array
    {
        $frames = [];
        foreach ($e->getTrace() as $frame) {
            $file = isset($frame['file']) ? (string) $frame['file'] : null;
            $line = isset($frame['line']) ? (int) $frame['line'] : null;

            $built = [
                'function' => isset($frame['function']) ? (string) $frame['function'] : null,
                'file' => $file,
                'line' => $line,
            ];

            if ($withSource && $file !== null && $line !== null) {
                $built += $this->sourceContext($file, $line);
            }

            $frames[] = $built;
        }

        return $frames;
    }

    /**
     * Read a window of source around $line: up to self::CONTEXT_LINES lines
     * before (`pre_context`), the line itself (`context_line`), and up to
     * self::CONTEXT_LINES after (`post_context`). Best-effort and fully
     * defensive — any unreadable / oversized / out-of-range file yields an empty
     * array, so a frame simply renders without a source window.
     *
     * @return array{pre_context?:list<string>,context_line?:string,post_context?:list<string>}
     */
    private function sourceContext(string $file, int $line): array
    {
        if ($line < 1 || !@is_file($file) || !@is_readable($file)) {
            return [];
        }

        $size = @filesize($file);
        if ($size === false || $size > self::MAX_SOURCE_BYTES) {
            return [];
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false || $line > count($lines)) {
            return [];
        }

        $index = $line - 1; // 0-based offset of the error line
        $start = max(0, $index - self::CONTEXT_LINES);

        return [
            'pre_context' => array_values(array_slice($lines, $start, $index - $start)),
            'context_line' => (string) $lines[$index],
            'post_context' => array_values(array_slice($lines, $index + 1, self::CONTEXT_LINES)),
        ];
    }

    private function normalizeLevel(string $level): string
    {
        $level = strtolower($level);

        return in_array($level, self::LEVELS, true) ? $level : 'error';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatch(array $payload): void
    {
        if ($this->dsn === null) {
            return;
        }
        $this->sender->send($this->dsn, $payload);
    }

    private static function isValidDsn(?string $dsn): bool
    {
        if ($dsn === null || $dsn === '') {
            return false;
        }

        return filter_var($dsn, FILTER_VALIDATE_URL) !== false;
    }
}

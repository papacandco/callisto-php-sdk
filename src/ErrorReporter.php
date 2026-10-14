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
    public const NAME = 'callisto-php/sdk';
    public const VERSION = '1.3.0';
    public const LANGUAGE = 'php';

    private const LEVELS = ['fatal', 'error', 'warning', 'info'];

    /** Source lines captured on each side of a frame's error line. */
    private const CONTEXT_LINES = 10;

    /** Skip source capture for files larger than this (bytes). */
    private const MAX_SOURCE_BYTES = 2_000_000;

    /** Replacement for any redacted header/query value. */
    private const REDACTED = '[Filtered]';

    /** Header/query keys whose value must never be transmitted. */
    private const SENSITIVE_KEYS = [
        'authorization', 'cookie', 'set-cookie', 'proxy-authorization',
        'x-api-key', 'x-csrf-token', 'x-xsrf-token',
        'php-auth-pw', 'php-auth-user',
    ];

    /** Key-name patterns that also force redaction. */
    private const SENSITIVE_KEY_PATTERN = '/authoriz|authentic|auth[-_]?(pw|user|pass)|cookie|secret|token|password|passwd|api[-_]?key|csrf|session/i';

    /** Caps to keep the request block bounded. */
    private const MAX_MAP_ITEMS = 50;
    private const MAX_VALUE_BYTES = 1024;

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
     * @param array{method:string,path:string,url?:string,query?:array,headers?:array,ip?:string}|null $request  inbound request
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
     * @param array{method:string,path:string,url?:string,query?:array,headers?:array,ip?:string}|null $request
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

        $requestId = $this->requestIdFrom($request);
        if ($requestId !== null) {
            $context['trace']['request_id'] = $requestId;
        }

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
            // The request body is deliberately never captured (PII guarantee).
            $req = [
                'method' => $request['method'],
                'path' => $request['path'],
            ];
            if (isset($request['url']) && is_string($request['url'])) {
                $req['url'] = $this->stripQuery($request['url']);
            }
            if (isset($request['query']) && is_array($request['query']) && $request['query'] !== []) {
                $req['query_string'] = $this->redactMap($request['query'], false);
            }
            if (isset($request['headers']) && is_array($request['headers']) && $request['headers'] !== []) {
                $req['headers'] = $this->redactMap($request['headers'], true);
            }
            $ip = $this->resolveIp($request);
            if ($ip !== null) {
                $req['ip'] = $ip;
            }
            $payload['request'] = $req;
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

        $context['runtime'] = [
            'name' => 'php',
            'version' => PHP_VERSION,
            'sapi' => php_sapi_name(),
            'os' => php_uname('s'),
            'os_version' => php_uname('r'),
        ];
        $host = gethostname();
        if ($host !== false) {
            $context['server'] = ['name' => $host];
        }
        $stats = ['memory_peak_bytes' => memory_get_peak_usage(true)];
        if (isset($_SERVER['REQUEST_TIME_FLOAT'])) {
            $stats['duration_ms'] = round((microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2);
        }
        $context['runtime_stats'] = $stats;
        $context['trace'] = ['trace_id' => bin2hex(random_bytes(16))];

        return $context;
    }

    /**
     * Where the error comes from in the application's own code: the first
     * in-app frame, as "function in path/relative/to/project.php". No line
     * number: the culprit is part of the issue fingerprint, and a line moves
     * with every edit above it (the frame keeps the line). The request route
     * is only the fallback, when no frame is in the application at all.
     *
     * @param array{method:string,path:string}|null $request
     */
    private function culprit(Throwable $e, ?array $request): string
    {
        foreach ($this->frames($e) as $frame) {
            if ($frame['in_app'] && $frame['file'] !== null) {
                return ($frame['function'] ?? '{main}') . ' in ' . $this->relativePath($frame['file']);
            }
        }

        if ($request !== null) {
            return $request['method'] . ' ' . $request['path'];
        }

        return $this->relativePath($e->getFile()) . ':' . $e->getLine();
    }

    /**
     * Build the frame list, innermost first: frame 0 is the line that raised
     * the error, which the dashboard opens and highlights. Each frame carries
     * function / file / line / in_app, and — when the source file is readable —
     * a Sentry-style source window around its line (`pre_context`,
     * `context_line`, `post_context`, up to self::CONTEXT_LINES each side).
     *
     * @param bool $withSource Whether to attach the source window (off for
     *                         transport errors — see buildExceptionPayload).
     * @return array<int, array{function:?string,file:?string,line:?int,in_app:bool,pre_context?:list<string>,context_line?:string,post_context?:list<string>}>
     */
    private function stacktrace(Throwable $e, bool $withSource): array
    {
        $frames = [];
        foreach ($this->frames($e) as $frame) {
            if ($withSource && $frame['file'] !== null && $frame['line'] !== null) {
                $frame += $this->sourceContext($frame['file'], $frame['line']);
            }
            $frames[] = $frame;
        }

        return $frames;
    }

    /**
     * Re-pair PHP's trace into "this function, at this line inside it".
     *
     * getTrace() does not contain the line that raised the error: entry k names
     * a function together with the line that CALLED it. Read as is, every
     * function sat next to its caller's line and the throw site
     * ($e->getFile():getLine()) was dropped, so the failing line of the
     * developer's code never appeared. Here the throw site is frame 0 and each
     * function is paired with the location inside it (the previous entry's
     * call site); the outermost location is the top-level script, {main}.
     *
     * An ErrorException raised by an error handler (a warning or notice turned
     * into an exception) has the handler as entry 0, called at the faulting
     * line: that entry is dropped, the faulting line is already frame 0.
     *
     * @return list<array{function:?string,file:?string,line:?int,in_app:bool}>
     */
    private function frames(Throwable $e): array
    {
        $trace = $e->getTrace();
        if (
            $e instanceof \ErrorException
            && isset($trace[0]['file'], $trace[0]['line'])
            && $trace[0]['file'] === $e->getFile()
            && (int) $trace[0]['line'] === $e->getLine()
        ) {
            array_shift($trace);
        }

        $frames = [];
        $file = $e->getFile();
        $line = $e->getLine();
        foreach ($trace as $call) {
            $frames[] = $this->frame($this->functionName($call), $file, $line);
            $file = isset($call['file']) ? (string) $call['file'] : null;
            $line = isset($call['line']) ? (int) $call['line'] : null;
        }
        if ($file !== null) {
            $frames[] = $this->frame('{main}', $file, $line);
        }

        return $frames;
    }

    /**
     * @return array{function:?string,file:?string,line:?int,in_app:bool}
     */
    private function frame(?string $function, ?string $file, ?int $line): array
    {
        return [
            'function' => $function,
            'file' => $file,
            'line' => $line,
            'in_app' => $file !== null && $this->isInApp($file),
        ];
    }

    /**
     * @param array<string, mixed> $call
     */
    private function functionName(array $call): ?string
    {
        if (!isset($call['function'])) {
            return null;
        }
        $function = (string) $call['function'];
        if (isset($call['class'])) {
            $function = $call['class'] . ($call['type'] ?? '::') . $function;
        }

        return $function;
    }

    /**
     * Application code, as opposed to dependencies (anything under vendor/)
     * and this SDK itself, whose frames only show how the error travelled.
     */
    private function isInApp(string $file): bool
    {
        $file = str_replace('\\', '/', $file);

        return !str_contains($file, '/vendor/')
            && !str_starts_with($file, str_replace('\\', '/', __DIR__) . '/');
    }

    /**
     * The path relative to the project root (the directory holding vendor/),
     * so a culprit stays the same whatever directory the project is deployed in.
     */
    private function relativePath(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        $sdk = str_replace('\\', '/', __DIR__);
        $at = strpos($sdk, '/vendor/');
        $root = $at !== false ? substr($sdk, 0, $at) : dirname($sdk);

        return str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : $file;
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

    /**
     * Redact a header/query map: sensitive keys' values become [Filtered];
     * other values are flattened (arrays joined) and length-capped. The map size
     * is capped. When $titleCaseKeys, header names are normalized to Title-Case.
     *
     * @param array<array-key, mixed> $map
     * @return array<string, string>
     */
    private function redactMap(array $map, bool $titleCaseKeys): array
    {
        $out = [];
        $count = 0;
        foreach ($map as $key => $value) {
            if ($count >= self::MAX_MAP_ITEMS) {
                break;
            }
            $count++;
            $rawKey = (string) $key;
            $name = $titleCaseKeys ? $this->titleCaseHeader($rawKey) : $rawKey;
            if ($this->isSensitiveKey($rawKey)) {
                $out[$name] = self::REDACTED;
                continue;
            }
            $flat = is_array($value)
                ? implode(', ', array_map(static fn ($v): string => (string) $v, $value))
                : (string) $value;
            if (strlen($flat) > self::MAX_VALUE_BYTES) {
                $flat = substr($flat, 0, self::MAX_VALUE_BYTES);
                // Drop a possibly-split trailing multibyte sequence so the value
                // stays valid UTF-8 (an invalid byte would make json_encode fail
                // and silently lose the whole event).
                $flat = (string) mb_convert_encoding($flat, 'UTF-8', 'UTF-8');
            }
            $out[$name] = $flat;
        }

        return $out;
    }

    private function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        if (in_array($lower, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        return preg_match(self::SENSITIVE_KEY_PATTERN, $lower) === 1;
    }

    private function titleCaseHeader(string $name): string
    {
        $parts = explode('-', strtolower($name));

        return implode('-', array_map(static fn (string $p): string => ucfirst($p), $parts));
    }

    private function stripQuery(string $url): string
    {
        $pos = strpos($url, '?');

        return $pos === false ? $url : substr($url, 0, $pos);
    }

    /**
     * Resolve the client IP: an explicit `ip` wins; otherwise derive from proxy
     * headers in order Cf-Connecting-Ip → X-Forwarded-For (first) → X-Real-Ip.
     *
     * @param array<string, mixed> $request
     */
    private function resolveIp(array $request): ?string
    {
        if (isset($request['ip']) && is_string($request['ip']) && $request['ip'] !== '') {
            return $request['ip'];
        }
        $headers = $request['headers'] ?? null;
        if (!is_array($headers)) {
            return null;
        }
        $lower = [];
        foreach ($headers as $k => $v) {
            $lower[strtolower((string) $k)] = is_array($v) ? (string) ($v[0] ?? '') : (string) $v;
        }
        foreach (['cf-connecting-ip', 'x-forwarded-for', 'x-real-ip'] as $h) {
            if (!empty($lower[$h])) {
                $val = $lower[$h];
                if ($h === 'x-forwarded-for') {
                    $val = trim(explode(',', $val)[0]);
                }

                return $val;
            }
        }

        return null;
    }

    /**
     * The inbound X-Request-Id (case-insensitive) for log correlation, or null.
     *
     * @param array<string, mixed>|null $request
     */
    private function requestIdFrom(?array $request): ?string
    {
        $headers = $request['headers'] ?? null;
        if (!is_array($headers)) {
            return null;
        }
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === 'x-request-id') {
                $val = is_array($v) ? (string) ($v[0] ?? '') : (string) $v;

                return $val === '' ? null : $val;
            }
        }

        return null;
    }
}

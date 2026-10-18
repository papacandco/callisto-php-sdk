<?php

declare(strict_types=1);

namespace Callisto\Sdk\Logs;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Throwable;

/**
 * PSR-3 adapter that forwards to a {@see Logger}. Usable only when `psr/log`
 * is installed (it is listed under composer `suggest`, not `require`).
 *
 * - `{placeholder}` in the message is interpolated from the context (PSR-3);
 * - `context['exception']` (a Throwable) becomes `exception.type`,
 *   `exception.message` and `exception.stacktrace`;
 * - every other context key becomes an attribute (`trace_id` / `span_id`
 *   included, so they tie the record to a trace); on a key clash the adapter's
 *   own `logger.name` / `exception.*` win;
 * - the optional channel name is sent as the `logger.name` attribute.
 *
 * Levels: debug → debug; info, notice → info; warning → warn; error, critical,
 * alert → error; emergency → fatal. An unknown level is logged as info rather
 * than throwing: a log call never throws.
 *
 * A record emitted while this adapter (or its logger) is already busy, for
 * example by an HTTP library logging into this same PSR-3 logger during the
 * send, is dropped, never re-shipped.
 */
final class PsrLogger implements LoggerInterface
{
    use LoggerTrait;

    private const LEVELS = [
        'debug' => 'debug',
        'info' => 'info',
        'notice' => 'info',
        'warning' => 'warn',
        'error' => 'error',
        'critical' => 'error',
        'alert' => 'error',
        'emergency' => 'fatal',
    ];

    private bool $forwarding = false;

    public function __construct(
        private readonly Logger $logger,
        private readonly ?string $name = null,
    ) {
    }

    public function logger(): Logger
    {
        return $this->logger;
    }

    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array<array-key, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        if ($this->forwarding || !$this->logger->isEnabled()) {
            return;
        }
        $this->forwarding = true;

        try {
            $level = is_string($level) || $level instanceof \Stringable ? strtolower((string) $level) : '';
            $attributes = $this->name !== null ? ['logger.name' => $this->name] : [];

            $exception = $context['exception'] ?? null;
            if ($exception instanceof Throwable) {
                unset($context['exception']);
                $attributes['exception.type'] = $exception::class;
                $attributes['exception.message'] = $exception->getMessage();
                $attributes['exception.stacktrace'] = (string) $exception;
            }

            $this->logger->log(
                self::LEVELS[$level] ?? 'info',
                self::interpolate((string) $message, $context),
                $attributes + $context,
            );
        } catch (Throwable) {
            // A log call never throws.
        } finally {
            $this->forwarding = false;
        }
    }

    /** @param array<array-key, mixed> $context */
    private static function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }

        return (string) preg_replace_callback(
            '/\{([A-Za-z0-9_.]+)\}/',
            static function (array $m) use ($context): string {
                if (!array_key_exists($m[1], $context)) {
                    return $m[0];
                }
                $value = $context[$m[1]];

                return match (true) {
                    $value === null => '',
                    is_bool($value) => $value ? 'true' : 'false',
                    is_scalar($value), $value instanceof \Stringable => (string) $value,
                    $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::RFC3339),
                    default => $m[0],
                };
            },
            $message,
        );
    }
}

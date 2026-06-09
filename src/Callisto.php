<?php

declare(strict_types=1);

namespace Callisto\Sdk;

use Callisto\Sdk\Error\Sender;
use Throwable;

/**
 * Static facade for standalone error reporting — no API {@see Client}, no
 * client id / api key required. Initialise once with a DSN, then capture from
 * anywhere in the process:
 *
 *   Callisto::init('https://ingest.callistosignal.com/app/<uuid>?key=<hex>');
 *   Callisto::captureException($e);
 *
 * It owns a single process-wide {@see ErrorReporter}. Every capture method is a
 * cheap no-op until {@see init} is called with a valid DSN and — like the
 * reporter it wraps — never throws.
 */
final class Callisto
{
    private static ?ErrorReporter $reporter = null;
    private static bool $unhandledInstalled = false;

    /**
     * Initialise the global reporter. Each argument falls back to the
     * environment when null/omitted: CALLISTO_APP_ERROR_DSN, CALLISTO_ENVIRONMENT,
     * CALLISTO_CAPTURE_UNHANDLED. Calling init again replaces the active
     * reporter (the global handler, once installed, stays installed).
     *
     * @param bool|null $captureUnhandled install global uncaught-exception /
     *        fatal-error handlers (only when the DSN is valid). Defaults off.
     */
    public static function init(
        ?string $dsn = null,
        ?string $environment = null,
        ?bool $captureUnhandled = null,
        ?Sender $sender = null,
    ): void {
        $dsn = $dsn ?? (getenv('CALLISTO_APP_ERROR_DSN') ?: null);
        $environment = $environment ?? (getenv('CALLISTO_ENVIRONMENT') ?: null);
        $captureUnhandled = $captureUnhandled ?? self::envBool('CALLISTO_CAPTURE_UNHANDLED', false);

        $reporter = new ErrorReporter($dsn, $environment, $sender);
        self::$reporter = $reporter;

        if ($captureUnhandled && $reporter->isEnabled() && !self::$unhandledInstalled) {
            self::$unhandledInstalled = true;
            UnhandledHandler::install($reporter);
        }
    }

    /** Whether reporting is active (init was called with a valid DSN). */
    public static function isEnabled(): bool
    {
        return self::$reporter?->isEnabled() ?? false;
    }

    /**
     * @param array<string, mixed>|null $extra
     */
    public static function captureException(Throwable $e, string $level = 'error', ?array $extra = null): void
    {
        self::$reporter?->captureException($e, $level, $extra);
    }

    /**
     * @param array<string, mixed>|null $extra
     */
    public static function captureMessage(string $message, string $level = 'info', ?array $extra = null): void
    {
        self::$reporter?->captureMessage($message, $level, $extra);
    }

    /**
     * Set or clear the user context attached to subsequent events.
     *
     * @param array<string, mixed>|null $user
     */
    public static function setUser(?array $user): void
    {
        self::$reporter?->setUser($user);
    }

    /** Drain pending sends (no-op for the synchronous PHP reporter). */
    public static function flush(): void
    {
        self::$reporter?->flush();
    }

    /**
     * The underlying reporter (advanced use); null until {@see init} is called.
     */
    public static function reporter(): ?ErrorReporter
    {
        return self::$reporter;
    }

    /**
     * Clear global state. Cannot uninstall an already-registered shutdown
     * function (PHP has no API for that); primarily for test isolation.
     */
    public static function reset(): void
    {
        self::$reporter = null;
        self::$unhandledInstalled = false;
    }

    private static function envBool(string $name, bool $default): bool
    {
        $raw = getenv($name);
        if ($raw === false || $raw === '') {
            return $default;
        }

        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }
}

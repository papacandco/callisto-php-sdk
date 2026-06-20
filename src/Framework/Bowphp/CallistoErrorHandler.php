<?php

declare(strict_types=1);

namespace Callisto\Sdk\Framework\Bowphp;

use Callisto\Sdk\Integration\CallistoIntegration;
use Throwable;

/**
 * BowPHP integration via configuration (not middleware). Bow renders uncaught
 * exceptions through the class registered as `error_handle` in config/app.php
 * (a {@code Bow\Application\Exception\BaseErrorHandler}). Report to Callisto from
 * that handler's handle() method — one line, alongside your existing rendering:
 *
 *   use Callisto\Sdk\Framework\Bowphp\CallistoErrorHandler;
 *
 *   class ErrorHandle extends \Bow\Application\Exception\BaseErrorHandler
 *   {
 *       public function handle($exception): mixed
 *       {
 *           CallistoErrorHandler::report($exception);
 *           // ... your existing rendering
 *       }
 *   }
 *
 * It reports with the current request's method/path, skips client-error (4xx)
 * HTTP exceptions via {@see CallistoIntegration::shouldReport}, and returns so
 * your handler renders the error page exactly as before.
 *
 * With no configured integration it builds one from CALLISTO_APP_ERROR_DSN /
 * CALLISTO_ENVIRONMENT; call {@see using()} once at boot to supply your own.
 * Duck-typed against Bow\Http\Request (method()/path()), so the SDK needs no
 * dependency on bowphp/framework.
 */
final class CallistoErrorHandler
{
    private static ?CallistoIntegration $shared = null;

    /**
     * Supply the integration used by {@see report()} when none is passed
     * explicitly. Call once during application boot.
     */
    public static function using(CallistoIntegration $integration): void
    {
        self::$shared = $integration;
    }

    /**
     * Report an exception to Callisto (best-effort, never throws). Intended to be
     * called from the `error_handle` handler's handle() method.
     */
    public static function report(Throwable $e, ?CallistoIntegration $integration = null): void
    {
        try {
            ($integration ?? self::shared())->captureUnhandled($e, self::requestFrom(self::currentRequest()));
        } catch (Throwable) {
            // Reporting must never disturb the host app's error rendering.
        }
    }

    /**
     * Shape a Bow request (method()/path()) into {method, path}. Static + public
     * so it is unit-testable with a stand-in request.
     *
     * @return array{method:string,path:string}|null
     */
    public static function requestFrom(?object $request): ?array
    {
        if ($request === null) {
            return null;
        }

        $method = method_exists($request, 'method') ? $request->method() : null;
        $path = method_exists($request, 'path') ? $request->path() : null;
        $url = method_exists($request, 'url') ? $request->url() : null;
        $query = method_exists($request, 'query') ? $request->query() : null;
        $headers = method_exists($request, 'getHeaders') ? $request->getHeaders() : null;
        $ip = method_exists($request, 'ip') ? $request->ip() : null;

        return CallistoIntegration::request(
            is_string($method) ? $method : null,
            is_string($path) ? $path : null,
            is_string($url) ? $url : null,
            is_array($query) ? $query : null,
            is_array($headers) ? $headers : null,
            is_string($ip) ? $ip : null,
        );
    }

    private static function shared(): CallistoIntegration
    {
        return self::$shared ??= CallistoIntegration::fromEnv();
    }

    /** The current Bow request via the global helper, or null outside HTTP. */
    private static function currentRequest(): ?object
    {
        if (!function_exists('request')) {
            return null;
        }

        try {
            $request = request();

            return is_object($request) ? $request : null;
        } catch (Throwable) {
            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace Callisto\Sdk\Framework\Laravel;

use Callisto\Sdk\ErrorReporter;
use Callisto\Sdk\Integration\CallistoIntegration;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Laravel integration. Auto-discovered via composer (extra.laravel.providers),
 * so installing the SDK in a Laravel app is enough — set CALLISTO_ERROR_DSN and
 * captured exceptions flow to Callisto with request + user context and a source
 * window on the failing line.
 *
 * It registers a {@see https://laravel.com/docs/errors#reporting-exceptions
 * reportable} callback on the framework's exception handler rather than
 * replacing it, so Laravel's own logging/rendering is untouched. Client-error
 * (4xx) HTTP exceptions are filtered out by {@see CallistoIntegration::shouldReport}.
 *
 * Requires the host app to provide laravel/framework (illuminate/*); the SDK
 * declares it only under "suggest".
 */
final class CallistoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CallistoIntegration::class, static function ($app): CallistoIntegration {
            $config = is_array($app['config']['callisto'] ?? null) ? $app['config']['callisto'] : [];

            $dsn = $config['dsn'] ?? (getenv('CALLISTO_ERROR_DSN') ?: null);
            $environment = $config['environment']
                ?? (getenv('CALLISTO_ENVIRONMENT') ?: null)
                ?? (method_exists($app, 'environment') ? $app->environment() : null);

            return CallistoIntegration::fromReporter(new ErrorReporter($dsn, $environment));
        });
    }

    public function boot(): void
    {
        $integration = $this->app->make(CallistoIntegration::class);
        if (!$integration->isEnabled()) {
            return;
        }

        $handler = $this->app->make(ExceptionHandler::class);
        // reportable() exists on Illuminate\Foundation\Exceptions\Handler; guard
        // for custom handlers that don't implement it.
        if (!method_exists($handler, 'reportable')) {
            return;
        }

        $handler->reportable(function (Throwable $e) use ($integration): void {
            $integration->captureUnhandled($e, $this->requestContext(), $this->userContext());
        });
    }

    /**
     * The current request as {method, path}, or null outside an HTTP context.
     *
     * @return array{method:string,path:string}|null
     */
    private function requestContext(): ?array
    {
        if (!$this->app->bound('request')) {
            return null;
        }

        $request = $this->app->make('request');
        if (!is_object($request)) {
            return null;
        }

        $method = method_exists($request, 'getMethod') ? (string) $request->getMethod() : null;
        $path = method_exists($request, 'path') ? '/' . ltrim((string) $request->path(), '/') : null;

        return CallistoIntegration::request($method, $path);
    }

    /**
     * The authenticated user as {id, email}, or null when unauthenticated.
     *
     * @return array<string, mixed>|null
     */
    private function userContext(): ?array
    {
        try {
            if (!$this->app->bound('auth')) {
                return null;
            }

            // The 'auth' binding is the AuthManager (a guard *factory*): its
            // user()/check() are proxied via __call to the default guard, so we
            // must go through guard() rather than call user() on the manager.
            $auth = $this->app->make('auth');
            if (!is_object($auth) || !method_exists($auth, 'guard')) {
                return null;
            }

            return CallistoIntegration::user($this->resolveUser($auth));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The current user from whichever guard authenticated the request — not just
     * the default guard. The default guard is tried first (the common case, as
     * the Authenticate middleware promotes the active guard to default), then
     * every configured guard, so a request authenticated on a non-default guard
     * (e.g. 'api' while the default is 'web') still resolves a user.
     */
    private function resolveUser(object $auth): ?object
    {
        $user = $auth->guard()->user();
        if (is_object($user)) {
            return $user;
        }

        $config = $this->app->bound('config') ? $this->app->make('config') : null;
        $guards = is_object($config) && method_exists($config, 'get')
            ? array_keys((array) $config->get('auth.guards', []))
            : [];

        foreach ($guards as $name) {
            $candidate = $auth->guard($name)->user();
            if (is_object($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}

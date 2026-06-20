<?php

declare(strict_types=1);

namespace Callisto\Sdk\Framework\Symfony;

use Callisto\Sdk\Integration\CallistoIntegration;
use Throwable;

/**
 * Symfony integration: a listener for the {@code kernel.exception} event that
 * forwards the thrown exception to Callisto with request context, then lets
 * Symfony render its response as usual (it neither stops propagation nor sets a
 * response). Client-error (4xx) HTTP exceptions are filtered by
 * {@see CallistoIntegration::shouldReport}.
 *
 * It is intentionally duck-typed against Symfony's {@code ExceptionEvent}
 * (getThrowable()/getRequest()) and {@code Request} (getMethod()/getPathInfo()),
 * so the SDK needs no dependency on symfony/http-kernel. Register it as a
 * service tagged for the exception event:
 *
 *   Callisto\Sdk\Framework\Symfony\CallistoExceptionListener:
 *     tags:
 *       - { name: kernel.event_listener, event: kernel.exception, method: onKernelException }
 *
 * With no constructor argument it configures itself from CALLISTO_APP_ERROR_DSN /
 * CALLISTO_ENVIRONMENT; inject a {@see CallistoIntegration} to override.
 */
final class CallistoExceptionListener
{
    private readonly CallistoIntegration $integration;

    public function __construct(?CallistoIntegration $integration = null)
    {
        $this->integration = $integration ?? CallistoIntegration::fromEnv();
    }

    public function onKernelException(object $event): void
    {
        if (!method_exists($event, 'getThrowable')) {
            return;
        }

        $throwable = $event->getThrowable();
        if (!$throwable instanceof Throwable) {
            return;
        }

        $this->integration->captureUnhandled($throwable, $this->requestContext($event));
    }

    /**
     * @return array{method:string,path:string}|null
     */
    private function requestContext(object $event): ?array
    {
        if (!method_exists($event, 'getRequest')) {
            return null;
        }

        $request = $event->getRequest();
        if (!is_object($request)) {
            return null;
        }

        $method = method_exists($request, 'getMethod') ? (string) $request->getMethod() : null;
        $path = method_exists($request, 'getPathInfo') ? (string) $request->getPathInfo() : null;

        $url = method_exists($request, 'getUri') ? (string) $request->getUri() : null;

        $query = null;
        if (isset($request->query) && is_object($request->query) && method_exists($request->query, 'all')) {
            $all = $request->query->all();
            $query = is_array($all) ? $all : null;
        }

        $headers = null;
        if (isset($request->headers) && is_object($request->headers) && method_exists($request->headers, 'all')) {
            $all = $request->headers->all();
            $headers = is_array($all) ? $all : null;
        }

        $ip = method_exists($request, 'getClientIp') ? $request->getClientIp() : null;

        return CallistoIntegration::request(
            $method,
            $path,
            $url,
            $query,
            $headers,
            is_string($ip) ? $ip : null,
        );
    }
}

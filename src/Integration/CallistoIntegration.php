<?php

declare(strict_types=1);

namespace Callisto\Sdk\Integration;

use Callisto\Sdk\Error\Sender;
use Callisto\Sdk\ErrorReporter;
use Throwable;

/**
 * Framework-neutral glue between a framework's exception pipeline and the
 * {@see ErrorReporter}. The per-framework bridges (Laravel service provider,
 * Symfony listener, BowPHP middleware) are thin shells that catch a throwable,
 * read the inbound request, and hand it here — so all the real decisions
 * (what's worth reporting, how to attach request/user) live in one tested place.
 *
 * Error reporting is decoupled from the API client: an app can enable it with
 * just a DSN (CALLISTO_APP_ERROR_DSN), no client id / api key required.
 */
final class CallistoIntegration
{
    public function __construct(
        private readonly ErrorReporter $reporter
    ) {
    }

    public static function fromReporter(ErrorReporter $reporter): self
    {
        return new self($reporter);
    }

    /**
     * Build from the environment: CALLISTO_APP_ERROR_DSN (required for reporting to
     * be active) and CALLISTO_ENVIRONMENT (optional tag). When the DSN is absent
     * the resulting integration is a cheap no-op.
     */
    public static function fromEnv(?Sender $sender = null): self
    {
        $dsn = getenv('CALLISTO_APP_ERROR_DSN') ?: null;
        $environment = getenv('CALLISTO_ENVIRONMENT') ?: null;

        return new self(new ErrorReporter($dsn, $environment, $sender));
    }

    /** The wrapped reporter (advanced use). */
    public function reporter(): ErrorReporter
    {
        return $this->reporter;
    }

    /** Whether reporting is active (a valid DSN was supplied). */
    public function isEnabled(): bool
    {
        return $this->reporter->isEnabled();
    }

    /**
     * Set or clear the user context attached to subsequent events.
     *
     * @param array<string, mixed>|null $user
     */
    public function setUser(?array $user): void
    {
        $this->reporter->setUser($user);
    }

    /**
     * Report a framework-caught exception (best-effort, never throws). No-ops
     * when reporting is disabled or {@see shouldReport} rejects the throwable.
     * The source window stays on (these are real application exceptions whose
     * request carries only method/path, never a body), so the dashboard shows
     * the failing code with the error line in focus.
     *
     * @param array{method:string,path:string}|null $request inbound request
     * @param array<string, mixed>|null             $user    affected user
     */
    public function captureUnhandled(
        Throwable $e,
        ?array $request = null,
        ?array $user = null,
        string $level = 'error',
    ): void {
        if (!$this->reporter->isEnabled() || !self::shouldReport($e)) {
            return;
        }

        if ($user !== null && $user !== []) {
            $this->reporter->setUser($user);
        }

        $this->reporter->captureException($e, $level, null, $request);
    }

    /**
     * Whether an exception is worth sending to an error tracker. Client-error
     * HTTP exceptions (4xx) — 404s, validation failures, auth redirects — are
     * routine routing noise, so they are skipped. Laravel, Symfony and BowPHP
     * all expose getStatusCode() on those, so this stays framework-neutral via a
     * duck-typed check. Server errors (>= 500) and ordinary exceptions report.
     */
    public static function shouldReport(Throwable $e): bool
    {
        if (method_exists($e, 'getStatusCode')) {
            /** @var mixed $status */
            $status = $e->getStatusCode();
            if (is_int($status) && $status >= 400 && $status < 500) {
                return false;
            }
        }

        return true;
    }

    /**
     * Shape an inbound request into the reporter's {method, path} contract, or
     * null when either part is missing.
     *
     * @return array{method:string,path:string}|null
     */
    public static function request(?string $method, ?string $path): ?array
    {
        $method = $method !== null ? trim($method) : '';
        $path = $path !== null ? trim($path) : '';
        if ($method === '' || $path === '') {
            return null;
        }

        return ['method' => strtoupper($method), 'path' => $path];
    }

    /**
     * Distil a framework user object into the reporter's {id, email} shape, or
     * null when nothing usable is present. Duck-typed so it works across
     * frameworks: it prefers the Laravel Authenticatable contract
     * (getAuthIdentifier()), falling back to a public ->id, plus a public ->email.
     *
     * @return array<string, mixed>|null
     */
    public static function user(?object $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $id = null;
        if (method_exists($user, 'getAuthIdentifier')) {
            $id = $user->getAuthIdentifier();
        } elseif (isset($user->id)) {
            $id = $user->id;
        }

        $data = [];
        if ($id !== null && $id !== '') {
            $data['id'] = (string) $id;
        }
        if (isset($user->email) && is_string($user->email) && $user->email !== '') {
            $data['email'] = $user->email;
        }

        return $data === [] ? null : $data;
    }
}

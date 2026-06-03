<?php

declare(strict_types=1);

namespace Callisto\Sdk;

use Callisto\Sdk\Error\Sender;
use Callisto\Sdk\Http\Transport;
use Callisto\Sdk\Resource\Balance;
use Callisto\Sdk\Resource\Notify;
use Callisto\Sdk\Resource\Otp;
use Callisto\Sdk\Resource\Sms;
use Callisto\Sdk\Resource\WhatsApp;
use GuzzleHttp\Client as GuzzleClient;
use Throwable;

final class Client
{
    private readonly Transport $transport;
    private readonly ErrorReporter $reporter;
    private bool $unhandledInstalled = false;
    private ?Balance $balance = null;
    private ?Sms $sms = null;
    private ?Otp $otp = null;
    private ?WhatsApp $whatsApp = null;
    private ?Notify $notify = null;

    public function __construct(
        ?string $clientId = null,
        ?string $apiKey = null,
        ?string $baseUrl = null,
        float $timeout = 30.0,
        ?GuzzleClient $httpClient = null,
        ?string $errorDsn = null,
        ?bool $captureUnhandled = null,
        ?string $environment = null,
        ?Sender $errorSender = null,
    ) {
        $config = Config::resolve(
            $clientId,
            $apiKey,
            $baseUrl,
            $timeout,
            $errorDsn,
            $captureUnhandled,
            $environment,
        );
        $this->reporter = new ErrorReporter($config->errorDsn, $config->environment, $errorSender);
        $this->transport = new Transport($config, $httpClient, $this->reporter);

        if ($config->captureUnhandled && $this->reporter->isEnabled()) {
            $this->installUnhandledHandler();
        }
    }

    public function balance(): Balance
    {
        return $this->balance ??= new Balance($this->transport);
    }

    public function sms(): Sms
    {
        return $this->sms ??= new Sms($this->transport);
    }

    public function otp(): Otp
    {
        return $this->otp ??= new Otp($this->transport);
    }

    public function whatsApp(): WhatsApp
    {
        return $this->whatsApp ??= new WhatsApp($this->transport);
    }

    public function notify(): Notify
    {
        return $this->notify ??= new Notify($this->transport);
    }

    /**
     * The underlying error reporter (advanced use). The supported surface is
     * {@see captureException}, {@see captureMessage} and {@see setUser}.
     */
    public function errorReporter(): ErrorReporter
    {
        return $this->reporter;
    }

    /**
     * Report an exception to the Callisto error-tracking endpoint (best-effort).
     *
     * @param array<string, mixed>|null $extra
     */
    public function captureException(Throwable $e, string $level = 'error', ?array $extra = null): void
    {
        $this->reporter->captureException($e, $level, $extra);
    }

    /**
     * Report a plain message to the Callisto error-tracking endpoint (best-effort).
     *
     * @param array<string, mixed>|null $extra
     */
    public function captureMessage(string $message, string $level = 'info', ?array $extra = null): void
    {
        $this->reporter->captureMessage($message, $level, $extra);
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
     * Drain pending sends (no-op for the synchronous PHP reporter).
     */
    public function flush(): void
    {
        $this->reporter->flush();
    }

    /**
     * Install global handlers that capture uncaught exceptions and fatal errors
     * at level `fatal`, chaining any previously-registered exception handler.
     */
    private function installUnhandledHandler(): void
    {
        if ($this->unhandledInstalled) {
            return;
        }
        $this->unhandledInstalled = true;
        $reporter = $this->reporter;

        $previous = set_exception_handler(static function (Throwable $e) use ($reporter, &$previous): void {
            $reporter->captureException($e, 'fatal');
            if ($previous !== null) {
                $previous($e);
            } else {
                // Preserve PHP's default behavior (re-raise).
                throw $e;
            }
        });

        register_shutdown_function(static function () use ($reporter): void {
            $error = error_get_last();
            if ($error === null) {
                return;
            }
            $fatalTypes = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
            if (($error['type'] & $fatalTypes) === 0) {
                return;
            }
            $throwable = new \ErrorException(
                $error['message'],
                0,
                $error['type'],
                $error['file'],
                $error['line'],
            );
            $reporter->captureException($throwable, 'fatal');
        });
    }
}

<?php

declare(strict_types=1);

namespace Callisto\Sdk;

use ErrorException;
use Throwable;

/**
 * Installs global handlers that route uncaught exceptions and fatal errors to an
 * {@see ErrorReporter} at level `fatal`. Any previously-registered exception
 * handler is chained, preserving prior behavior (and PHP's default re-raise when
 * there was none).
 *
 * Installation is intentionally not guarded here: callers decide when to install
 * ({@see Client} guards per-instance, {@see Callisto} guards per-process), so the
 * wiring itself lives in exactly one place.
 */
final class UnhandledHandler
{
    public static function install(ErrorReporter $reporter): void
    {
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
            $throwable = new ErrorException(
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

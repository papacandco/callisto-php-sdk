<?php

declare(strict_types=1);

namespace Callisto\Sdk\Tests\Framework;

use Callisto\Sdk\ErrorReporter;
use Callisto\Sdk\Exception\CallistoException;
use Callisto\Sdk\Framework\Symfony\CallistoExceptionListener;
use Callisto\Sdk\Integration\CallistoIntegration;
use Callisto\Sdk\Tests\Support\RecordingSender;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;

class SymfonyExceptionListenerTest extends TestCase
{
    private const DSN = 'https://app.callistosignal.com/ingest/abc?key=deadbeef';

    private function listener(RecordingSender $sender, ?string $env = 'production'): CallistoExceptionListener
    {
        return new CallistoExceptionListener(
            CallistoIntegration::fromReporter(new ErrorReporter(self::DSN, $env, $sender))
        );
    }

    /** A stand-in for Symfony's ExceptionEvent (duck-typed). */
    private function event(Throwable $e, string $method, string $path): object
    {
        $request = new class ($method, $path) {
            public function __construct(private string $method, private string $path)
            {
            }

            public function getMethod(): string
            {
                return $this->method;
            }

            public function getPathInfo(): string
            {
                return $this->path;
            }
        };

        return new class ($e, $request) {
            public function __construct(private Throwable $throwable, private object $request)
            {
            }

            public function getThrowable(): Throwable
            {
                return $this->throwable;
            }

            public function getRequest(): object
            {
                return $this->request;
            }
        };
    }

    public function testReportsExceptionWithRequest(): void
    {
        $sender = new RecordingSender();
        $this->listener($sender)->onKernelException(
            $this->event(new RuntimeException('symfony-boom'), 'post', '/checkout')
        );

        $this->assertCount(1, $sender->payloads);
        $this->assertSame(['method' => 'POST', 'path' => '/checkout'], $sender->last()['request']);
        $this->assertSame('RuntimeException', $sender->last()['type']);
    }

    public function testSkipsClientErrorHttpException(): void
    {
        $sender = new RecordingSender();
        $this->listener($sender)->onKernelException(
            $this->event(CallistoException::fromStatus(404, 'nf', null), 'GET', '/missing')
        );

        $this->assertSame([], $sender->payloads);
    }

    public function testIgnoresMalformedEvent(): void
    {
        $sender = new RecordingSender();
        // An object that is not an ExceptionEvent must be ignored, not fatal.
        $this->listener($sender)->onKernelException(new stdClass());

        $this->assertSame([], $sender->payloads);
    }
}

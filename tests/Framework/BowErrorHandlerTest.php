<?php

declare(strict_types=1);

namespace Callisto\Sdk\Tests\Framework;

use Callisto\Sdk\ErrorReporter;
use Callisto\Sdk\Exception\CallistoException;
use Callisto\Sdk\Framework\Bowphp\CallistoErrorHandler;
use Callisto\Sdk\Integration\CallistoIntegration;
use Callisto\Sdk\Tests\Support\RecordingSender;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BowErrorHandlerTest extends TestCase
{
    private const DSN = 'https://app.callistosignal.com/ingest/abc?key=deadbeef';

    private function integration(RecordingSender $sender, ?string $env = 'production'): CallistoIntegration
    {
        return CallistoIntegration::fromReporter(new ErrorReporter(self::DSN, $env, $sender));
    }

    /** A stand-in for Bow\Http\Request (duck-typed). */
    private function request(string $method, string $path): object
    {
        return new class ($method, $path) {
            public function __construct(private string $method, private string $path)
            {
            }

            public function method(): string
            {
                return $this->method;
            }

            public function path(): string
            {
                return $this->path;
            }
        };
    }

    public function testReportsExceptionAndDoesNotThrow(): void
    {
        $sender = new RecordingSender();

        // report() never re-throws — the handler renders the response itself.
        CallistoErrorHandler::report(new RuntimeException('bow-boom'), $this->integration($sender));

        $this->assertCount(1, $sender->payloads);
        $this->assertSame('RuntimeException', $sender->last()['type']);
    }

    public function testSkipsClientErrorHttpException(): void
    {
        $sender = new RecordingSender();
        CallistoErrorHandler::report(CallistoException::fromStatus(404, 'nf', null), $this->integration($sender));

        $this->assertSame([], $sender->payloads);
    }

    public function testRequestShapingFromBowRequest(): void
    {
        $this->assertSame(
            ['method' => 'DELETE', 'path' => '/items/9'],
            CallistoErrorHandler::requestFrom($this->request('delete', '/items/9'))
        );
        $this->assertNull(CallistoErrorHandler::requestFrom(null));
        // An object without method()/path() yields no request, not a fatal.
        $this->assertNull(CallistoErrorHandler::requestFrom(new \stdClass()));
    }

    public function testNeverThrowsEvenWithoutConfiguredIntegration(): void
    {
        // No DSN configured anywhere → no-op, and report() must still not throw.
        putenv('CALLISTO_ERROR_DSN');
        CallistoErrorHandler::report(new RuntimeException('x'));
        $this->addToAssertionCount(1);
    }
}

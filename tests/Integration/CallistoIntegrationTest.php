<?php

declare(strict_types=1);

namespace Callisto\Sdk\Tests\Integration;

use Callisto\Sdk\ErrorReporter;
use Callisto\Sdk\Exception\CallistoException;
use Callisto\Sdk\Integration\CallistoIntegration;
use Callisto\Sdk\Tests\Support\RecordingSender;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CallistoIntegrationTest extends TestCase
{
    private const DSN = 'https://app.callistosignal.com/ingest/abc?key=deadbeef';

    private function integration(RecordingSender $sender): CallistoIntegration
    {
        return CallistoIntegration::fromReporter(new ErrorReporter(self::DSN, 'production', $sender));
    }

    public function testReportsServerExceptionWithRequestAndSourceWindow(): void
    {
        $sender = new RecordingSender();
        $integration = $this->integration($sender);

        try {
            throw new RuntimeException('boom-in-app');
        } catch (RuntimeException $e) {
            $integration->captureUnhandled($e, CallistoIntegration::request('get', '/orders/42'));
        }

        $this->assertCount(1, $sender->payloads);
        $payload = $sender->last();

        // Request method/path are attached and normalized.
        $this->assertSame(['method' => 'GET', 'path' => '/orders/42'], $payload['request']);

        // Unlike SDK transport errors, framework captures keep the source window:
        // at least one frame carries the failing line + context.
        $this->assertArrayHasKey('stacktrace', $payload);
        $withSource = array_filter(
            $payload['stacktrace'],
            static fn (array $frame): bool => array_key_exists('context_line', $frame)
        );
        $this->assertNotEmpty($withSource, 'expected a source window on a framework-caught exception');
    }

    public function testSkipsClientErrorHttpException(): void
    {
        $sender = new RecordingSender();
        // 404 → has getStatusCode() === 404 → routing noise, not reported.
        $notFound = CallistoException::fromStatus(404, 'Not Found', null);

        $this->integration($sender)->captureUnhandled($notFound);

        $this->assertSame([], $sender->payloads);
    }

    public function testReportsServerErrorHttpException(): void
    {
        $sender = new RecordingSender();
        $serverError = CallistoException::fromStatus(500, 'Server Error', null);

        $this->integration($sender)->captureUnhandled($serverError);

        $this->assertCount(1, $sender->payloads);
        $this->assertSame('error', $sender->last()['level']);
    }

    public function testAttachesUserContext(): void
    {
        $sender = new RecordingSender();
        $this->integration($sender)->captureUnhandled(
            new RuntimeException('x'),
            null,
            ['id' => '7', 'email' => 'a@b.com'],
        );

        $this->assertSame(['id' => '7', 'email' => 'a@b.com'], $sender->last()['user']);
    }

    public function testDisabledIntegrationIsNoOp(): void
    {
        $sender = new RecordingSender();
        $integration = CallistoIntegration::fromReporter(new ErrorReporter(null, null, $sender));

        $this->assertFalse($integration->isEnabled());
        $integration->captureUnhandled(new RuntimeException('x'), CallistoIntegration::request('GET', '/x'));

        $this->assertSame([], $sender->payloads);
    }

    public function testFromEnvReadsDsnAndEnvironment(): void
    {
        putenv('CALLISTO_ERROR_DSN=' . self::DSN);
        putenv('CALLISTO_ENVIRONMENT=staging');
        try {
            $sender = new RecordingSender();
            // fromEnv builds its own reporter; inject the recording sender.
            $integration = CallistoIntegration::fromEnv($sender);
            $this->assertTrue($integration->isEnabled());

            $integration->captureUnhandled(new RuntimeException('env-boom'));
            $this->assertCount(1, $sender->payloads);
            $this->assertSame('staging', $sender->last()['context']['environment']);
        } finally {
            putenv('CALLISTO_ERROR_DSN');
            putenv('CALLISTO_ENVIRONMENT');
        }
    }

    public function testRequestShaping(): void
    {
        $this->assertSame(['method' => 'GET', 'path' => '/x'], CallistoIntegration::request('get', '/x'));
        $this->assertNull(CallistoIntegration::request(null, '/x'));
        $this->assertNull(CallistoIntegration::request('GET', ''));
        $this->assertNull(CallistoIntegration::request('  ', '  '));
    }

    public function testUserExtraction(): void
    {
        $this->assertNull(CallistoIntegration::user(null));

        // Laravel Authenticatable contract: getAuthIdentifier() wins over ->id.
        $authenticatable = new class {
            public int $id = 99;
            public string $email = 'a@b.com';

            public function getAuthIdentifier(): int
            {
                return 42;
            }
        };
        $this->assertSame(
            ['id' => '42', 'email' => 'a@b.com'],
            CallistoIntegration::user($authenticatable)
        );

        // Plain object with public id / email.
        $plain = new class {
            public string $id = 'u-7';
            public string $email = 'c@d.com';
        };
        $this->assertSame(['id' => 'u-7', 'email' => 'c@d.com'], CallistoIntegration::user($plain));

        // Nothing usable → null.
        $this->assertNull(CallistoIntegration::user(new \stdClass()));
    }

    public function testShouldReport(): void
    {
        $this->assertFalse(CallistoIntegration::shouldReport(CallistoException::fromStatus(404, 'nf', null)));
        $this->assertFalse(CallistoIntegration::shouldReport(CallistoException::fromStatus(422, 'invalid', null)));
        $this->assertTrue(CallistoIntegration::shouldReport(CallistoException::fromStatus(500, 'err', null)));
        $this->assertTrue(CallistoIntegration::shouldReport(new RuntimeException('plain')));
    }
}

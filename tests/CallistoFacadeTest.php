<?php

declare(strict_types=1);

namespace Callisto\Sdk\Tests;

use Callisto\Sdk\Callisto;
use Callisto\Sdk\Tests\Support\RecordingSender;
use PHPUnit\Framework\TestCase as BaseTestCase;
use RuntimeException;

/**
 * The static facade for standalone (clientless) error reporting.
 */
class CallistoFacadeTest extends BaseTestCase
{
    private const DSN = 'https://app.callistosignal.com/ingest/abc?key=deadbeef';

    private RecordingSender $sender;

    protected function setUp(): void
    {
        $this->sender = new RecordingSender();
        Callisto::reset();
    }

    protected function tearDown(): void
    {
        Callisto::reset();
        putenv('CALLISTO_APP_ERROR_DSN');
        putenv('CALLISTO_ENVIRONMENT');
        putenv('CALLISTO_CAPTURE_UNHANDLED');
    }

    public function testCaptureBeforeInitIsSilentNoop(): void
    {
        $this->assertFalse(Callisto::isEnabled());
        $this->assertNull(Callisto::reporter());

        // Must not throw even though init() was never called.
        Callisto::captureException(new RuntimeException('boom'));
        Callisto::captureMessage('hello');
        Callisto::setUser(['id' => 'u-1']);
        Callisto::flush();

        $this->assertSame([], $this->sender->payloads);
    }

    public function testInitThenCaptureExceptionSendsPayload(): void
    {
        Callisto::init(self::DSN, sender: $this->sender);

        $this->assertTrue(Callisto::isEnabled());
        Callisto::captureException(new RuntimeException('kaboom'), 'warning');

        $this->assertCount(1, $this->sender->payloads);
        $payload = $this->sender->last();
        $this->assertSame('kaboom', $payload['message']);
        $this->assertSame(RuntimeException::class, $payload['type']);
        $this->assertSame('warning', $payload['level']);
    }

    public function testInitThenCaptureMessage(): void
    {
        Callisto::init(self::DSN, environment: 'production', sender: $this->sender);

        Callisto::captureMessage('payment retried', 'info');

        $payload = $this->sender->last();
        $this->assertSame('payment retried', $payload['message']);
        $this->assertSame('info', $payload['level']);
        $this->assertSame('production', $payload['context']['environment']);
    }

    public function testSetUserIsAttachedToSubsequentEvents(): void
    {
        Callisto::init(self::DSN, sender: $this->sender);
        Callisto::setUser(['id' => 'u-123', 'email' => 'user@example.com']);

        Callisto::captureException(new RuntimeException('x'));

        $payload = $this->sender->last();
        $this->assertSame(['id' => 'u-123', 'email' => 'user@example.com'], $payload['user']);
    }

    public function testInitWithoutDsnIsDisabledNoop(): void
    {
        Callisto::init(null, sender: $this->sender);

        $this->assertFalse(Callisto::isEnabled());
        Callisto::captureException(new RuntimeException('x'));
        $this->assertSame([], $this->sender->payloads);
    }

    public function testInitWithInvalidDsnIsDisabled(): void
    {
        Callisto::init('not-a-url', sender: $this->sender);

        $this->assertFalse(Callisto::isEnabled());
        Callisto::captureException(new RuntimeException('x'));
        $this->assertSame([], $this->sender->payloads);
    }

    public function testInitResolvesDsnAndEnvironmentFromEnv(): void
    {
        putenv('CALLISTO_APP_ERROR_DSN=' . self::DSN);
        putenv('CALLISTO_ENVIRONMENT=staging');

        Callisto::init(sender: $this->sender);

        $this->assertTrue(Callisto::isEnabled());
        Callisto::captureMessage('from env');

        $payload = $this->sender->last();
        $this->assertSame('staging', $payload['context']['environment']);
    }

    public function testExplicitArgumentsOverrideEnv(): void
    {
        putenv('CALLISTO_ENVIRONMENT=staging');

        Callisto::init(self::DSN, environment: 'production', sender: $this->sender);
        Callisto::captureMessage('x');

        $this->assertSame('production', $this->sender->last()['context']['environment']);
    }

    public function testResetDisablesReporting(): void
    {
        Callisto::init(self::DSN, sender: $this->sender);
        $this->assertTrue(Callisto::isEnabled());

        Callisto::reset();

        $this->assertFalse(Callisto::isEnabled());
        $this->assertNull(Callisto::reporter());
    }

    public function testCaptureUnhandledInstallsHandlerAndKeepsReportingEnabled(): void
    {
        Callisto::init(self::DSN, captureUnhandled: true, sender: $this->sender);

        $this->assertTrue(Callisto::isEnabled());

        // init() must have registered a global exception handler. Probe it, then
        // unwind both our probe and init()'s handler so the suite is left with
        // the original handler it started with.
        $installed = set_exception_handler(null);
        restore_exception_handler(); // undo the set_exception_handler(null) probe
        $this->assertNotNull($installed);
        restore_exception_handler(); // undo init()'s handler -> original baseline
    }
}

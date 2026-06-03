<?php

declare(strict_types=1);

namespace Callisto\Sdk\Tests;

use Callisto\Sdk\Client;
use Callisto\Sdk\Error\Sender;
use Callisto\Sdk\ErrorReporter;
use Callisto\Sdk\Exception\NotFoundException;
use Callisto\Sdk\Exception\ValidationException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase as BaseTestCase;
use RuntimeException;

/**
 * Fake sender that synchronously records every (dsn, payload) it is given.
 */
final class RecordingSender implements Sender
{
    /** @var array<int, array{dsn:string,payload:array<string,mixed>}> */
    public array $sent = [];

    public bool $throwOnSend = false;

    public function send(string $dsn, array $payload): void
    {
        if ($this->throwOnSend) {
            throw new RuntimeException('sender exploded');
        }
        $this->sent[] = ['dsn' => $dsn, 'payload' => $payload];
    }

    /** @return array<string, mixed> */
    public function lastPayload(): array
    {
        return $this->sent[count($this->sent) - 1]['payload'];
    }

    public function lastDsn(): string
    {
        return $this->sent[count($this->sent) - 1]['dsn'];
    }
}

class ReporterTest extends BaseTestCase
{
    private const DSN = 'https://app.callistosignal.com/ingest/abc?key=deadbeef';

    private RecordingSender $sender;

    protected function setUp(): void
    {
        $this->sender = new RecordingSender();
    }

    /**
     * Build a Client wired with mock Guzzle responses + a recording error sender.
     *
     * @param array<int, array{0:int,1:array,2?:array}> $responses
     */
    private function clientWith(array $responses, RecordingSender $sender): Client
    {
        $queue = array_map(
            fn (array $r) => new Response(
                $r[0],
                ['Content-Type' => 'application/json'] + ($r[2] ?? []),
                json_encode($r[1])
            ),
            $responses
        );
        $stack = HandlerStack::create(new MockHandler($queue));
        $guzzle = new GuzzleClient(['handler' => $stack]);

        return new Client(
            clientId: 'cid',
            apiKey: 'secret',
            baseUrl: 'https://api.test/v1',
            httpClient: $guzzle,
            errorDsn: self::DSN,
            errorSender: $sender,
        );
    }

    private function reporter(?RecordingSender $sender = null, ?string $dsn = self::DSN): ErrorReporter
    {
        return new ErrorReporter($dsn, 'production', $sender ?? $this->sender);
    }

    // 1. captured CallistoError -> POST to DSN with correct message/type/level
    public function testCaptureExceptionPostsToDsnWithCoreFields(): void
    {
        $this->reporter()->captureException(
            new NotFoundException('Message not found', 404, ['message' => 'Message not found'])
        );

        $this->assertCount(1, $this->sender->sent);
        $this->assertSame(self::DSN, $this->sender->lastDsn());

        $payload = $this->sender->lastPayload();
        $this->assertSame('Message not found', $payload['message']);
        $this->assertSame(NotFoundException::class, $payload['type']);
        $this->assertSame('error', $payload['level']);
    }

    // 2. context.sdk + status_code + request.{method,path} present for transport errors
    public function testTransportErrorIncludesSdkStatusAndRequest(): void
    {
        $client = $this->clientWith([[404, ['message' => 'Message not found']]], $this->sender);

        try {
            $client->sms()->getStatus('x');
            $this->fail('expected NotFoundException');
        } catch (NotFoundException $e) {
            // expected
        }

        $payload = $this->sender->lastPayload();
        $this->assertSame('callisto/sdk', $payload['context']['sdk']['name']);
        $this->assertSame('php', $payload['context']['sdk']['language']);
        $this->assertArrayHasKey('version', $payload['context']['sdk']);
        $this->assertSame(404, $payload['context']['status_code']);
        $this->assertSame('GET', $payload['request']['method']);
        $this->assertSame('/sms/x', $payload['request']['path']);
        $this->assertSame('GET ' . $payload['request']['path'], $payload['culprit']);
    }

    // 3. No credential or request-body leak.
    public function testNoCredentialOrRequestBodyLeak(): void
    {
        $client = $this->clientWith([[404, ['message' => 'not found']]], $this->sender);

        try {
            // sends a body with a phone number + message content
            $client->otp()->verify('otp-1', '123456');
            $this->fail('expected exception');
        } catch (NotFoundException $e) {
            // expected
        }

        $encoded = json_encode($this->sender->lastPayload());
        $this->assertIsString($encoded);
        $this->assertStringNotContainsStringIgnoringCase('secret', $encoded);
        $this->assertStringNotContainsStringIgnoringCase('cid', $encoded);
        $this->assertStringNotContainsStringIgnoringCase('authorization', $encoded);
        $this->assertStringNotContainsString('Basic ', $encoded);
        // outgoing request body fields must not leak
        $this->assertStringNotContainsString('otp-1', $encoded);
        $this->assertStringNotContainsString('123456', $encoded);
    }

    // 4. Sender failures (throw / non-202) are swallowed; capture never raises.
    public function testSenderFailuresAreSwallowed(): void
    {
        $sender = new RecordingSender();
        $sender->throwOnSend = true;
        $reporter = $this->reporter($sender);

        // Must not throw.
        $reporter->captureException(new ValidationException('boom'));
        $reporter->captureMessage('hello');

        $this->assertSame([], $sender->sent);
    }

    public function testNon202IsSwallowedViaGuzzleSender(): void
    {
        // A real GuzzleSender hitting a 401 must not throw.
        $stack = HandlerStack::create(new MockHandler([new Response(401, [], '{"error":"invalid_key"}')]));
        $guzzle = new GuzzleClient(['handler' => $stack]);
        $reporter = new ErrorReporter(self::DSN, null, new \Callisto\Sdk\Error\GuzzleSender($guzzle));

        $reporter->captureException(new ValidationException('boom'));
        $this->addToAssertionCount(1); // reached here without throwing
    }

    // 5. No DSN -> nothing sent, original error still propagates.
    public function testNoDsnIsNoOp(): void
    {
        $reporter = new ErrorReporter(null, null, $this->sender);
        $this->assertFalse($reporter->isEnabled());

        $reporter->captureException(new ValidationException('x'));
        $reporter->captureMessage('y');
        $reporter->setUser(['id' => 1]);
        $reporter->flush();

        $this->assertSame([], $this->sender->sent);
    }

    public function testInvalidDsnIsNoOp(): void
    {
        $reporter = new ErrorReporter('not-a-url', null, $this->sender);
        $this->assertFalse($reporter->isEnabled());
        $reporter->captureException(new ValidationException('x'));
        $this->assertSame([], $this->sender->sent);
    }

    public function testOriginalErrorStillPropagatesWithReporting(): void
    {
        $client = $this->clientWith([[404, ['message' => 'nope']]], $this->sender);
        $this->expectException(NotFoundException::class);
        $client->sms()->getStatus('x');
    }

    public function testOriginalErrorStillPropagatesWithoutDsn(): void
    {
        $stack = HandlerStack::create(new MockHandler([new Response(404, ['Content-Type' => 'application/json'], '{"message":"nope"}')]));
        $guzzle = new GuzzleClient(['handler' => $stack]);
        $client = new Client(
            clientId: 'cid',
            apiKey: 'secret',
            baseUrl: 'https://api.test/v1',
            httpClient: $guzzle,
        );
        $this->expectException(NotFoundException::class);
        $client->sms()->getStatus('x');
    }

    // 6. public methods + message/user/level handling.
    public function testCaptureMessageUsesContextAndLevel(): void
    {
        $reporter = $this->reporter();
        $reporter->setUser(['id' => 'u-1', 'email' => 'a@b.com']);
        $reporter->captureMessage('something happened', 'warning', ['feature' => 'otp']);

        $payload = $this->sender->lastPayload();
        $this->assertSame('something happened', $payload['message']);
        $this->assertSame('warning', $payload['level']);
        $this->assertSame('otp', $payload['context']['feature']);
        $this->assertSame('production', $payload['context']['environment']);
        $this->assertSame(['id' => 'u-1', 'email' => 'a@b.com'], $payload['user']);
    }

    public function testInvalidLevelFallsBackToError(): void
    {
        $this->reporter()->captureException(new ValidationException('x'), 'bogus');
        $this->assertSame('error', $this->sender->lastPayload()['level']);
    }

    public function testClientPublicMethodsDelegate(): void
    {
        $client = $this->clientWith([], $this->sender);
        $client->setUser(['id' => 7]);
        $client->captureMessage('hi', 'info');
        $client->captureException(new RuntimeException('host error'), 'error', ['k' => 'v']);

        $this->assertCount(2, $this->sender->sent);
        $msg = $this->sender->sent[0]['payload'];
        $this->assertSame('hi', $msg['message']);
        $exc = $this->sender->sent[1]['payload'];
        $this->assertSame(RuntimeException::class, $exc['type']);
        $this->assertSame('v', $exc['context']['k']);
        $this->assertSame(['id' => 7], $exc['user']);
        $this->assertSame($client->errorReporter(), $client->errorReporter());
    }

    public function testResourceValidationErrorIsCaptured(): void
    {
        $client = $this->clientWith([], $this->sender);

        try {
            $client->notify()->send(topic: 't');
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            // expected
        }

        $this->assertCount(1, $this->sender->sent);
        $this->assertSame(ValidationException::class, $this->sender->lastPayload()['type']);
    }

    public function testStacktraceIncludedAndRateLimitRetryAfter(): void
    {
        $reporter = $this->reporter();
        $reporter->captureException(
            new \Callisto\Sdk\Exception\RateLimitException('slow down', 429, ['message' => 'slow down'], 30)
        );
        $payload = $this->sender->lastPayload();
        $this->assertSame(30, $payload['context']['retry_after']);
        $this->assertSame(429, $payload['context']['status_code']);
        $this->assertIsArray($payload['stacktrace']);
        $this->assertArrayHasKey('function', $payload['stacktrace'][0]);
    }
}

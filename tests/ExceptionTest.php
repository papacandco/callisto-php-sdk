<?php

declare(strict_types=1);

namespace Callisto\Sdk\Tests;

use Callisto\Sdk\Exception\ApiException;
use Callisto\Sdk\Exception\AuthenticationException;
use Callisto\Sdk\Exception\CallistoException;
use Callisto\Sdk\Exception\NotFoundException;
use Callisto\Sdk\Exception\RateLimitException;
use Callisto\Sdk\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

class ExceptionTest extends TestCase
{
    public static function statusProvider(): array
    {
        return [
            [401, AuthenticationException::class],
            [400, ValidationException::class],
            [422, ValidationException::class],
            [404, NotFoundException::class],
            [429, RateLimitException::class],
            [500, ApiException::class],
        ];
    }

    /** @dataProvider statusProvider */
    public function testMapsStatusToClass(int $status, string $class): void
    {
        $err = CallistoException::fromStatus($status, 'msg', ['k' => 1]);
        $this->assertInstanceOf($class, $err);
        $this->assertInstanceOf(CallistoException::class, $err);
        $this->assertSame($status, $err->getStatusCode());
        $this->assertSame('msg', $err->getMessage());
        $this->assertSame(['k' => 1], $err->getBody());
    }
}

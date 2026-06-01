# callisto/sdk (PHP)

Official Callisto messaging API SDK for PHP 8.1+.

## Install

```bash
composer require callisto/sdk
```

## Quick start

```php
use Callisto\Sdk\Client;

$callisto = new Client(
    clientId: '...',   // or set CALLISTO_CLIENT_ID
    apiKey: '...',     // or set CALLISTO_API_KEY
    // baseUrl defaults to https://api.callistosignal.com/v1
);

$balance = $callisto->balance()->get();

$callisto->sms()->send(
    sender: 'Acme',
    to: ['+2250700000000'],
    message: 'Hello from Callisto!',
);
```

## Resources

- `$callisto->balance()->get(format: 'full', currency: null)`
- `$callisto->sms()->send(...)` · `list(...)` · `getStatus($id)`
- `$callisto->otp()->send(...)` · `verify(otpId, code)` · `getStatus($id)` · `list(...)`
- `$callisto->whatsApp()->createInstance(...)` · `listInstances($page)` · `getInstance($code)` · `getQr($code)` · `getStatus($code)` · `listMessages($code, ...)` · `getMessage($id)` · `sendText/sendMedia/sendButtons/sendLocation/sendList($code, ...)`
- `$callisto->notify()->send(topic: ..., sms: [...], email: [...], ...)`

## Errors

All API errors extend `Callisto\Sdk\Exception\CallistoException` and expose
`getStatusCode()`, `getMessage()`, and `getBody()`:
`AuthenticationException` (401), `ValidationException` (400/422),
`NotFoundException` (404), `RateLimitException` (429), `ApiException` (other),
`NetworkException` (transport failure).

```php
use Callisto\Sdk\Exception\RateLimitException;

try {
    $callisto->sms()->send(sender: 'Acme', to: '+225...', message: 'hi');
} catch (RateLimitException $e) {
    // back off and retry
    $seconds = $e->getRetryAfter(); // int|null from the Retry-After header
}
```

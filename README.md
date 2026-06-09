# callisto/sdk (PHP)

Official Callisto Signal SDK for PHP 8.1+. Two capabilities in one package:

- **Messaging API** — SMS, OTP, WhatsApp, Notify, and balance, over a typed, Guzzle-backed client. See [Quick start](#quick-start) and [Resources](#resources).
- **Error tracking** — opt-in, Sentry-style exception reporting with a source window on the failing line, plus drop-in **Laravel, Symfony and BowPHP** integrations. See [Error reporting](#error-reporting) → [Framework integration](#framework-integration).

## Requirements

- PHP **8.1+** (uses enums, readonly properties, named arguments)
- [`ext-json`](https://www.php.net/manual/en/book.json.php) (bundled with PHP)
- [Guzzle](https://docs.guzzlephp.org/) `^7.5` — the HTTP transport (installed automatically)

## Install

```bash
composer require callisto/sdk
```

## Configuration

The client is constructed directly with named arguments. The constructor signature is:

```php
public function __construct(
    ?string $clientId = null,
    ?string $apiKey = null,
    ?string $baseUrl = null,
    float $timeout = 30.0,
    ?GuzzleHttp\Client $httpClient = null,
)
```

```php
use Callisto\Sdk\Client;

$callisto = new Client(
    clientId: 'your-client-id',
    apiKey: 'your-api-key',
    baseUrl: 'https://api.callistosignal.com/v1', // optional
    timeout: 30.0,                                 // optional, seconds
);
```

**Defaults**

| Argument     | Default                                  |
| ------------ | ---------------------------------------- |
| `baseUrl`    | `https://api.callistosignal.com/v1`      |
| `timeout`    | `30.0` (seconds)                         |
| `httpClient` | a fresh `GuzzleHttp\Client` (inject your own for testing) |

**Environment-variable fallback** — when `clientId`, `apiKey`, or `baseUrl` are omitted (or `null`), they are resolved from the environment:

| Argument   | Env var               |
| ---------- | --------------------- |
| `clientId` | `CALLISTO_CLIENT_ID`  |
| `apiKey`   | `CALLISTO_API_KEY`    |
| `baseUrl`  | `CALLISTO_BASE_URL`   |

```php
use Callisto\Sdk\Client;

// Reads CALLISTO_CLIENT_ID / CALLISTO_API_KEY / CALLISTO_BASE_URL.
$callisto = new Client();
```

If neither the `clientId`/`apiKey` arguments nor their env vars are set, the constructor throws `InvalidArgumentException`. A trailing slash on `baseUrl` is stripped automatically.

**Authentication** is HTTP Basic and applied automatically on every request — `clientId` is the username and `apiKey` is the password. You never set headers yourself.

## Quick start

```php
use Callisto\Sdk\Client;
use Callisto\Sdk\Exception\CallistoException;

$callisto = new Client(clientId: 'your-client-id', apiKey: 'your-api-key');

try {
    // Check your balance
    $balance = $callisto->balance()->get();

    // Send an SMS
    $result = $callisto->sms()->send(
        sender: 'Acme',
        to: '+2250700000000',
        message: 'Welcome to Acme!',
    );

    echo $result['status'];
} catch (CallistoException $e) {
    echo "API error ({$e->getStatusCode()}): {$e->getMessage()}";
}
```

## Resources

Resources are reached through accessor methods on the client: `balance()`, `sms()`, `otp()`, `whatsApp()`, and `notify()`. Each accessor returns a cached resource instance.

> **Reads vs. writes.** Write/action endpoints (`send`, `verify`, `balance`) return a raw `array<string, mixed>` decoded straight from the JSON response. Typed **read** endpoints (`getStatus`, `getInstance`, `getMessage`, and the `list*` methods) return [typed model objects](#typed-models) instead. The per-method tables below state the exact return type.

---

### `balance()`

#### `get(string $format = 'full', ?string $currency = null): array`

| Parameter  | Type      | Required | Description                                              |
| ---------- | --------- | -------- | -------------------------------------------------------- |
| `format`   | `string`  | no       | Response detail level. Defaults to `'full'`.             |
| `currency` | `?string` | no       | Currency code to express the balance in (optional).      |

Returns `array<string, mixed>`.

```php
$balance = $callisto->balance()->get();
$balance = $callisto->balance()->get(format: 'full', currency: 'XOF');
```

---

### `sms()`

#### `send(string $sender, string|array $to, string $message, ?string $notifyUrl = null, ?string $scheduledAt = null): array`

| Parameter     | Type                       | Required | Description                                                    |
| ------------- | -------------------------- | -------- | -------------------------------------------------------------- |
| `sender`      | `string`                   | yes      | Approved sender name, e.g. `"Acme"`.                           |
| `to`          | `string \| array<string>`  | yes      | One recipient (string) or many (array of E.164 numbers).       |
| `message`     | `string`                   | yes      | Message body.                                                  |
| `notifyUrl`   | `?string`                  | no       | Webhook URL for delivery-status callbacks.                     |
| `scheduledAt` | `?string`                  | no       | Schedule send time, e.g. `"2026-06-02 10:00:00"`.              |

Returns `array<string, mixed>`.

```php
$callisto->sms()->send(
    sender: 'Acme',
    to: '+2250700000000',
    message: 'Your code is 1234',
);

// Multiple recipients + scheduling
$callisto->sms()->send(
    sender: 'Acme',
    to: ['+2250700000000', '+2250700000001'],
    message: 'Sale starts tomorrow!',
    notifyUrl: 'https://acme.example/webhooks/sms',
    scheduledAt: '2026-06-02 10:00:00',
);
```

#### `list(?string $startedAt = null, ?string $endedAt = null, ?int $page = null, ?int $perPage = null): Paginated`

| Parameter   | Type      | Required | Description                          |
| ----------- | --------- | -------- | ------------------------------------ |
| `startedAt` | `?string` | no       | Filter: start of date range.         |
| `endedAt`   | `?string` | no       | Filter: end of date range.           |
| `page`      | `?int`    | no       | Page number.                         |
| `perPage`   | `?int`    | no       | Items per page.                      |

Returns [`Paginated`](#paginated) of [`SmsMessage`](#smsmessage).

```php
$messages = $callisto->sms()->list(page: 1, perPage: 50);
foreach ($messages->items as $msg) {
    echo "{$msg->recipient}: {$msg->status}\n";
}
```

#### `getStatus(string $messageId): SmsMessage`

| Parameter   | Type     | Required | Description          |
| ----------- | -------- | -------- | -------------------- |
| `messageId` | `string` | yes      | The SMS message ID.  |

Returns [`SmsMessage`](#smsmessage).

```php
$msg = $callisto->sms()->getStatus('msg_abc123');
echo $msg->status;
```

---

### `otp()`

#### `send(string $to, string $message, ?string $sender = null, ?int $expiredIn = null, OtpType|string|null $type = null, ?int $digitSize = null, OtpProvider|string|null $provider = null, ?string $instanceCode = null): array`

| Parameter      | Type                                    | Required    | Description                                                                 |
| -------------- | --------------------------------------- | ----------- | --------------------------------------------------------------------------- |
| `to`           | `string`                                | yes         | Recipient phone number.                                                     |
| `message`      | `string`                                | yes         | Message template; typically contains a placeholder for the code.            |
| `sender`       | `?string`                               | no          | Sender name.                                                                |
| `expiredIn`    | `?int`                                  | no          | Code lifetime in seconds.                                                   |
| `type`         | [`OtpType`](#otptype)`\|string`         | no          | Code character set (`digit`, `alpha`, `alphanumeric`).                      |
| `digitSize`    | `?int`                                  | no          | Number of characters in the code.                                           |
| `provider`     | [`OtpProvider`](#otpprovider)`\|string` | no          | Delivery channel (`sms`, `whatsapp`).                                       |
| `instanceCode` | `?string`                               | conditional | WhatsApp instance code. **Required when `provider` is `whatsapp`** — the SDK throws `ValidationException` otherwise. |

Returns `array<string, mixed>`.

```php
use Callisto\Sdk\Enum\OtpType;
use Callisto\Sdk\Enum\OtpProvider;

// SMS OTP
$otp = $callisto->otp()->send(
    to: '+2250700000000',
    message: 'Your Acme code is {{code}}',
    sender: 'Acme',
    type: OtpType::Digit,
    digitSize: 6,
    expiredIn: 300,
);

// WhatsApp OTP — instanceCode is required
$callisto->otp()->send(
    to: '+2250700000000',
    message: 'Your code is {{code}}',
    provider: OtpProvider::Whatsapp,
    instanceCode: 'inst_abc123',
);
```

#### `verify(string $otpId, string $code): array`

| Parameter | Type     | Required | Description                  |
| --------- | -------- | -------- | ---------------------------- |
| `otpId`   | `string` | yes      | The OTP ID from `send()`.    |
| `code`    | `string` | yes      | The code entered by the user.|

Returns `array<string, mixed>`.

```php
$result = $callisto->otp()->verify(otpId: 'otp_abc123', code: '123456');
```

#### `getStatus(string $otpId): Otp`

| Parameter | Type     | Required | Description   |
| --------- | -------- | -------- | ------------- |
| `otpId`   | `string` | yes      | The OTP ID.   |

Returns [`Otp`](#otp-model).

```php
$otp = $callisto->otp()->getStatus('otp_abc123');
echo $otp->status;
```

#### `list(?string $startedAt = null, ?string $endedAt = null, ?int $page = null, ?int $limit = null): Paginated`

| Parameter   | Type      | Required | Description                   |
| ----------- | --------- | -------- | ----------------------------- |
| `startedAt` | `?string` | no       | Filter: start of date range.  |
| `endedAt`   | `?string` | no       | Filter: end of date range.    |
| `page`      | `?int`    | no       | Page number.                  |
| `limit`     | `?int`    | no       | Items per page.               |

Returns [`Paginated`](#paginated) of [`Otp`](#otp-model).

```php
$otps = $callisto->otp()->list(page: 1, limit: 20);
foreach ($otps->items as $otp) {
    echo "{$otp->recipient}: {$otp->status}\n";
}
```

---

### `whatsApp()`

#### `createInstance(string $name, ?string $phoneNumber = null, ?string $webhookUrl = null, ?string $idempotencyKey = null): WhatsAppInstance`

| Parameter        | Type      | Required | Description                                   |
| ---------------- | --------- | -------- | --------------------------------------------- |
| `name`           | `string`  | yes      | Display name for the instance.                |
| `phoneNumber`    | `?string` | no       | Phone number to bind to the instance.         |
| `webhookUrl`     | `?string` | no       | Webhook URL for inbound/status events.        |
| `idempotencyKey` | `?string` | no       | Key to make instance creation idempotent.     |

Returns [`WhatsAppInstance`](#whatsappinstance).

```php
$instance = $callisto->whatsApp()->createInstance(
    name: 'Acme Support',
    phoneNumber: '+2250700000000',
    webhookUrl: 'https://acme.example/webhooks/wa',
);
echo $instance->code;
```

#### `listInstances(int $page = 1): Paginated`

| Parameter | Type  | Required | Description                |
| --------- | ----- | -------- | -------------------------- |
| `page`    | `int` | no       | Page number (default `1`). |

Returns [`Paginated`](#paginated) of [`WhatsAppInstance`](#whatsappinstance).

```php
$instances = $callisto->whatsApp()->listInstances(page: 1);
```

#### `getInstance(string $code): WhatsAppInstance`

| Parameter | Type     | Required | Description           |
| --------- | -------- | -------- | --------------------- |
| `code`    | `string` | yes      | The instance code.    |

Returns [`WhatsAppInstance`](#whatsappinstance).

```php
$instance = $callisto->whatsApp()->getInstance('inst_abc123');
```

#### `getQr(string $code): array`

| Parameter | Type     | Required | Description           |
| --------- | -------- | -------- | --------------------- |
| `code`    | `string` | yes      | The instance code.    |

Returns `array<string, mixed>` (the QR-code payload to scan for pairing).

```php
$qr = $callisto->whatsApp()->getQr('inst_abc123');
```

#### `getStatus(string $code): array`

| Parameter | Type     | Required | Description           |
| --------- | -------- | -------- | --------------------- |
| `code`    | `string` | yes      | The instance code.    |

Returns `array<string, mixed>` (connection/session status of the instance).

```php
$status = $callisto->whatsApp()->getStatus('inst_abc123');
```

#### `listMessages(string $code, ?string $startedAt = null, ?string $endedAt = null, ?int $page = null, ?int $perPage = null): Paginated`

| Parameter   | Type      | Required | Description                  |
| ----------- | --------- | -------- | ---------------------------- |
| `code`      | `string`  | yes      | The instance code.           |
| `startedAt` | `?string` | no       | Filter: start of date range. |
| `endedAt`   | `?string` | no       | Filter: end of date range.   |
| `page`      | `?int`    | no       | Page number.                 |
| `perPage`   | `?int`    | no       | Items per page.              |

Returns [`Paginated`](#paginated) of [`WhatsAppMessage`](#whatsappmessage).

```php
$messages = $callisto->whatsApp()->listMessages('inst_abc123', page: 1, perPage: 50);
```

#### `getMessage(string $messageId): WhatsAppMessage`

| Parameter   | Type     | Required | Description              |
| ----------- | -------- | -------- | ------------------------ |
| `messageId` | `string` | yes      | The WhatsApp message ID. |

Returns [`WhatsAppMessage`](#whatsappmessage).

```php
$msg = $callisto->whatsApp()->getMessage('wamsg_abc123');
echo $msg->status;
```

#### `sendText(string $code, string $to, string $message, ?string $scheduledAt = null): array`

| Parameter     | Type      | Required | Description                       |
| ------------- | --------- | -------- | --------------------------------- |
| `code`        | `string`  | yes      | The instance code.                |
| `to`          | `string`  | yes      | Recipient phone number.           |
| `message`     | `string`  | yes      | Text message body.                |
| `scheduledAt` | `?string` | no       | Schedule send time.               |

Returns `array<string, mixed>`.

```php
$callisto->whatsApp()->sendText(
    code: 'inst_abc123',
    to: '+2250700000000',
    message: 'Hello from Acme!',
);
```

#### `sendMedia(string $code, string $to, WhatsAppMediaType|string $type, string $mediaUrl, ?string $caption = null, ?string $filename = null, ?string $scheduledAt = null): array`

| Parameter     | Type                                                | Required | Description                            |
| ------------- | --------------------------------------------------- | -------- | -------------------------------------- |
| `code`        | `string`                                            | yes      | The instance code.                     |
| `to`          | `string`                                            | yes      | Recipient phone number.                |
| `type`        | [`WhatsAppMediaType`](#whatsappmediatype)`\|string` | yes      | `image`, `video`, `document`, `audio`. |
| `mediaUrl`    | `string`                                            | yes      | URL of the media to send.              |
| `caption`     | `?string`                                           | no       | Caption text.                          |
| `filename`    | `?string`                                           | no       | Filename (useful for documents).       |
| `scheduledAt` | `?string`                                           | no       | Schedule send time.                    |

Returns `array<string, mixed>`.

```php
use Callisto\Sdk\Enum\WhatsAppMediaType;

$callisto->whatsApp()->sendMedia(
    code: 'inst_abc123',
    to: '+2250700000000',
    type: WhatsAppMediaType::Image,
    mediaUrl: 'https://acme.example/promo.jpg',
    caption: 'Check this out!',
);
```

#### `sendButtons(string $code, string $to, string $body, array $buttons, ?string $header = null, ?string $footer = null, ?string $scheduledAt = null): array`

| Parameter     | Type                               | Required | Description                 |
| ------------- | ---------------------------------- | -------- | --------------------------- |
| `code`        | `string`                           | yes      | The instance code.          |
| `to`          | `string`                           | yes      | Recipient phone number.     |
| `body`        | `string`                           | yes      | Main message text.          |
| `buttons`     | `array<int, array<string, mixed>>` | yes      | List of button definitions. |
| `header`      | `?string`                          | no       | Header text.                |
| `footer`      | `?string`                          | no       | Footer text.                |
| `scheduledAt` | `?string`                          | no       | Schedule send time.         |

Returns `array<string, mixed>`.

```php
$callisto->whatsApp()->sendButtons(
    code: 'inst_abc123',
    to: '+2250700000000',
    body: 'Confirm your order?',
    buttons: [
        ['id' => 'yes', 'title' => 'Yes'],
        ['id' => 'no',  'title' => 'No'],
    ],
);
```

#### `sendLocation(string $code, string $to, float $latitude, float $longitude, ?string $name = null, ?string $address = null, ?string $scheduledAt = null): array`

| Parameter     | Type      | Required | Description             |
| ------------- | --------- | -------- | ----------------------- |
| `code`        | `string`  | yes      | The instance code.      |
| `to`          | `string`  | yes      | Recipient phone number. |
| `latitude`    | `float`   | yes      | Latitude.               |
| `longitude`   | `float`   | yes      | Longitude.              |
| `name`        | `?string` | no       | Location name.          |
| `address`     | `?string` | no       | Location address.       |
| `scheduledAt` | `?string` | no       | Schedule send time.     |

Returns `array<string, mixed>`.

```php
$callisto->whatsApp()->sendLocation(
    code: 'inst_abc123',
    to: '+2250700000000',
    latitude: 5.3599,
    longitude: -4.0083,
    name: 'Acme HQ',
    address: 'Abidjan, Côte d\'Ivoire',
);
```

#### `sendList(string $code, string $to, string $body, string $buttonText, array $sections, ?string $header = null, ?string $footer = null, ?string $scheduledAt = null): array`

| Parameter     | Type                               | Required | Description                     |
| ------------- | ---------------------------------- | -------- | ------------------------------- |
| `code`        | `string`                           | yes      | The instance code.              |
| `to`          | `string`                           | yes      | Recipient phone number.         |
| `body`        | `string`                           | yes      | Main message text.              |
| `buttonText`  | `string`                           | yes      | Label for the list-open button. |
| `sections`    | `array<int, array<string, mixed>>` | yes      | List sections with their rows.  |
| `header`      | `?string`                          | no       | Header text.                    |
| `footer`      | `?string`                          | no       | Footer text.                    |
| `scheduledAt` | `?string`                          | no       | Schedule send time.             |

Returns `array<string, mixed>`.

```php
$callisto->whatsApp()->sendList(
    code: 'inst_abc123',
    to: '+2250700000000',
    body: 'Pick a plan',
    buttonText: 'View plans',
    sections: [
        [
            'title' => 'Plans',
            'rows'  => [
                ['id' => 'basic', 'title' => 'Basic'],
                ['id' => 'pro',   'title' => 'Pro'],
            ],
        ],
    ],
);
```

---

### `notify()`

#### `send(string $topic, ?array $email = null, ?array $sms = null, ?array $mobilePush = null, ?array $webPush = null, ?array $webhook = null, ?array $messaging = null, ?array $realTime = null): array`

Multi-channel notification dispatch. **At least one event block must be provided** — calling `send()` with only a `topic` (or with every block empty) throws `ValidationException`.

| Parameter    | Type                               | Required | Description                        |
| ------------ | ---------------------------------- | -------- | ---------------------------------- |
| `topic`      | `string`                           | yes      | Notification topic / template key. |
| `email`      | `array<int, array<string, mixed>>` | no\*     | Email event block.                 |
| `sms`        | `array<int, array<string, mixed>>` | no\*     | SMS event block.                   |
| `mobilePush` | `array<int, array<string, mixed>>` | no\*     | Mobile-push event block.           |
| `webPush`    | `array<int, array<string, mixed>>` | no\*     | Web-push event block.              |
| `webhook`    | `array<int, array<string, mixed>>` | no\*     | Webhook event block.               |
| `messaging`  | `array<int, array<string, mixed>>` | no\*     | Messaging event block.             |
| `realTime`   | `array<int, array<string, mixed>>` | no\*     | Real-time event block.             |

\* Each block is individually optional, but **at least one** of them must be present and non-empty.

Returns `array<string, mixed>`.

```php
$callisto->notify()->send(
    topic: 'order.shipped',
    email: [
        ['to' => 'customer@example.com', 'subject' => 'Your order shipped'],
    ],
    sms: [
        ['to' => '+2250700000000', 'message' => 'Your order is on the way!'],
    ],
);
```

## Pagination

List endpoints return a [`Paginated`](#paginated) object — a `final readonly` value object with these properties:

| Property      | Type          | Description                                |
| ------------- | ------------- | ------------------------------------------ |
| `items`       | `list<Model>` | The page of typed model objects.           |
| `total`       | `int`         | Total number of items across all pages.    |
| `perPage`     | `int`         | Items per page.                            |
| `currentPage` | `int`         | The current page number.                   |
| `next`        | `?int`        | Next page number, or `null` if none.       |
| `previous`    | `?int`        | Previous page number, or `null` if none.   |
| `totalPages`  | `int`         | Total number of pages.                     |

```php
$page = $callisto->sms()->list(page: 1, perPage: 50);

echo "Page {$page->currentPage} of {$page->totalPages} ({$page->total} total)\n";

foreach ($page->items as $message) {
    // $message is an SmsMessage instance
    echo "{$message->id} → {$message->status}\n";
}

if ($page->next !== null) {
    $next = $callisto->sms()->list(page: $page->next, perPage: 50);
}
```

## Typed models

All models live under `Callisto\Sdk\Model`, are declared `final readonly`, and are built via a static `fromArray()` factory. `fromArray()` tolerates missing or extra keys: absent values fall back to `null` (or `''`/`0` for non-nullable scalars), and unknown keys are ignored — so new API fields will not break deserialization.

### `Paginated`

See [Pagination](#pagination) above.

### `SmsMessage`

| Property     | Type      |
| ------------ | --------- |
| `id`         | `string`  |
| `senderName` | `?string` |
| `recipient`  | `?string` |
| `content`    | `?string` |
| `status`     | `?string` |
| `createdAt`  | `?string` |
| `updatedAt`  | `?string` |

### `Otp` (model)

`Callisto\Sdk\Model\Otp`. This model carries **both** `otpId` and `id`: `getStatus()` responses populate `otpId`, while `list()` rows populate `id`. Read whichever is relevant to the call you made.

| Property     | Type      |
| ------------ | --------- |
| `otpId`      | `?string` |
| `id`         | `?string` |
| `status`     | `?string` |
| `recipient`  | `?string` |
| `expiresAt`  | `?string` |
| `verifiedAt` | `?string` |
| `attempts`   | `?int`    |
| `createdAt`  | `?string` |

### `WhatsAppInstance`

| Property             | Type      |
| -------------------- | --------- |
| `id`                 | `string`  |
| `code`               | `?string` |
| `clientId`           | `?string` |
| `name`               | `?string` |
| `phoneNumber`        | `?string` |
| `phoneName`          | `?string` |
| `status`             | `?string` |
| `billingStatus`      | `?string` |
| `trialDaysRemaining` | `?int`    |
| `monthlyFee`         | `?float`  |
| `messagesSentToday`  | `?int`    |
| `messagesSentMonth`  | `?int`    |
| `dailyLimit`         | `?int`    |
| `lastMessageAt`      | `?string` |
| `webhookUrl`         | `?string` |
| `isActive`           | `?bool`   |
| `createdAt`          | `?string` |
| `updatedAt`          | `?string` |

### `WhatsAppMessage`

| Property              | Type                    |
| --------------------- | ----------------------- |
| `id`                  | `string`                |
| `instanceId`          | `?string`               |
| `clientId`            | `?string`               |
| `apiClientId`         | `?string`               |
| `recipient`           | `?string`               |
| `recipientName`       | `?string`               |
| `messageType`         | `?string`               |
| `content`             | `?string`               |
| `mediaUrl`            | `?string`               |
| `mediaMimetype`       | `?string`               |
| `mediaFilename`       | `?string`               |
| `extraData`           | `?array<string, mixed>` |
| `direction`           | `?string`               |
| `status`              | `?string`               |
| `whatsappMessageId`   | `?string`               |
| `errorCode`           | `?int`                  |
| `errorMessage`        | `?string`               |
| `retryCount`          | `?int`                  |
| `isBillable`          | `?bool`                 |
| `cost`                | `?float`                |
| `sentAt`              | `?string`               |
| `deliveredAt`         | `?string`               |
| `readAt`              | `?string`               |
| `scheduledAt`         | `?string`               |
| `createdAt`           | `?string`               |
| `updatedAt`           | `?string`               |
| `processorIdentifier` | `?string`               |

## Enums

All enums live under `Callisto\Sdk\Enum` and are backed by strings. Anywhere an enum is accepted you may also pass its raw string value.

### `OtpType`

| Case           | Value            |
| -------------- | ---------------- |
| `Digit`        | `'digit'`        |
| `Alpha`        | `'alpha'`        |
| `Alphanumeric` | `'alphanumeric'` |

### `OtpProvider`

| Case       | Value        |
| ---------- | ------------ |
| `Sms`      | `'sms'`      |
| `Whatsapp` | `'whatsapp'` |

### `WhatsAppMediaType`

| Case       | Value        |
| ---------- | ------------ |
| `Image`    | `'image'`    |
| `Video`    | `'video'`    |
| `Document` | `'document'` |
| `Audio`    | `'audio'`    |

## Error handling

All SDK errors extend `Callisto\Sdk\Exception\CallistoException`, which exposes:

- `getMessage(): string` — the error message (from the API `message` field when present, else `HTTP <status>`).
- `getStatusCode(): int` — the HTTP status code (`0` for transport-level failures).
- `getBody(): mixed` — the decoded JSON response body, when available.

HTTP responses are mapped to specific subclasses:

| Exception                 | When                                                            |
| ------------------------- | -------------------------------------------------------------- |
| `AuthenticationException` | HTTP `401`                                                     |
| `ValidationException`     | HTTP `400` or `422` (also thrown client-side for invalid input) |
| `NotFoundException`       | HTTP `404`                                                     |
| `RateLimitException`      | HTTP `429` — adds `getRetryAfter(): ?int` (seconds, from the `Retry-After` header) |
| `ApiException`            | Any other `>= 400` status                                      |
| `NetworkException`        | Transport failure (DNS, connection, timeout)                   |

```php
use Callisto\Sdk\Exception\AuthenticationException;
use Callisto\Sdk\Exception\ValidationException;
use Callisto\Sdk\Exception\NotFoundException;
use Callisto\Sdk\Exception\RateLimitException;
use Callisto\Sdk\Exception\NetworkException;
use Callisto\Sdk\Exception\CallistoException;

try {
    $callisto->sms()->send(
        sender: 'Acme',
        to: '+2250700000000',
        message: 'Hello!',
    );
} catch (RateLimitException $e) {
    $wait = $e->getRetryAfter() ?? 1;
    sleep($wait);
    // ...retry
} catch (ValidationException $e) {
    echo "Invalid request: {$e->getMessage()}";
    var_dump($e->getBody());
} catch (AuthenticationException $e) {
    echo 'Check your client ID and API key.';
} catch (NotFoundException $e) {
    echo 'Resource not found.';
} catch (NetworkException $e) {
    echo "Network problem: {$e->getMessage()}";
} catch (CallistoException $e) {
    // Catch-all for any remaining API error (ApiException, etc.)
    echo "API error ({$e->getStatusCode()}): {$e->getMessage()}";
}
```

## Error reporting

The SDK ships an opt-in, Sentry-style error reporter that POSTs captured errors to a
Callisto error-tracking **ingest endpoint**. It auto-captures the SDK's own
`CallistoException`s (API + network + client-side validation) and exposes a public API so
your application can report its own exceptions. Reporting is **fully disabled** unless a DSN
is configured.

Each captured event carries the exception message, type, level, and a normalized stack trace
that includes a **source window** — the failing line plus up to five lines of surrounding
context — so the dashboard highlights exactly where the error occurred. (The window is omitted
for the SDK's own transport errors, whose call sites could embed request data — see
[PII guarantee](#pii-guarantee).)

> **Delivery is synchronous best-effort (PHP-specific).** PHP has no portable background
> threads in a request context, so the reporter delivers each event **inline with a short
> timeout (2s)**. Every failure — any exception, any non-202 — is swallowed. Reporting never
> alters or blocks the original error path, and `captureException`/`captureMessage` never throw.

### Enabling

Pass a DSN (constructor argument or env var). The DSN **is** the full ingest URL
(`{APP_URL}/apps/{id}?key={public_key}`):

```php
use Callisto\Sdk\Client;

$callisto = new Client(
    clientId: 'your-client-id',
    apiKey: 'your-api-key',
    errorDsn: 'https://ingest.callistosignal.com/apps/<uuid>?key=<hex>', // enables reporting
    captureUnhandled: true,        // optional, default false — install global handler
    environment: 'production',     // optional, tagged in context.environment
);
```

### Standalone (without the API client)

Don't need the API client (SMS / OTP / WhatsApp)? Use the `Callisto` static facade to report
errors with **just a DSN** — no `clientId` / `apiKey`. Initialise once at boot, then capture
from anywhere in the process:

```php
use Callisto\Sdk\Callisto;

Callisto::init(
    dsn: 'https://ingest.callistosignal.com/apps/<uuid>?key=<hex>',
    environment: 'production', // optional
    captureUnhandled: true,    // optional, default false — install global handler
);

Callisto::captureException($throwable, level: 'error', extra: ['feature' => 'checkout']);
Callisto::captureMessage('payment retried', level: 'info');
Callisto::setUser(['id' => 'u-123', 'email' => 'user@example.com']);
Callisto::flush();
```

Every argument falls back to the same [environment variables](#environment-variables) when
omitted, so `Callisto::init()` with no arguments works once `CALLISTO_APP_ERROR_DSN` is set. The
static methods mirror the [instance API](#public-api) one-for-one, and are safe no-ops before
`init()` (or when the DSN is absent) — they never throw. `Callisto::reporter()` exposes the
underlying reporter for advanced use.

### Environment variables

When the corresponding argument is omitted (or `null`), it is resolved from the environment:

| Argument           | Env var                      | Default | Meaning                                                     |
| ------------------ | ---------------------------- | ------- | ---------------------------------------------------------- |
| `errorDsn`         | `CALLISTO_APP_ERROR_DSN`         | none    | Ingest DSN. Absent (or not a valid URL) → reporting disabled (no-op). |
| `captureUnhandled` | `CALLISTO_CAPTURE_UNHANDLED` | `false` | Install the global unhandled-exception / fatal handler.    |
| `environment`      | `CALLISTO_ENVIRONMENT`       | none    | Optional tag included in `context.environment`.            |

### Public API

```php
// Report your own exceptions and messages.
$callisto->captureException($throwable, level: 'error', extra: ['feature' => 'checkout']);
$callisto->captureMessage('payment retried', level: 'info');

// Attach user context to subsequent events (pass null to clear).
$callisto->setUser(['id' => 'u-123', 'email' => 'user@example.com']);

// Best-effort flush (no-op for the synchronous PHP reporter).
$callisto->flush();

// Advanced: the reporter itself.
$reporter = $callisto->errorReporter();
```

`level` is constrained to `fatal | error | warning | info` (anything else falls back to `error`).

### Opt-in global handler

When `captureUnhandled` is `true` **and** a DSN is set, the client installs
`set_exception_handler` and a `register_shutdown_function` (for fatal errors) that report at
level `fatal`. Both **chain** any previously-registered handler / preserve PHP's default
behavior (the exception is still re-raised), so they never clobber existing error handling.

### PII guarantee

The reporter **never transmits** your `clientId`, `apiKey`, the `Authorization` header, or the
**outgoing request body** (which carries phone numbers and message content). Only the server's
error response `body`, `status_code`, the HTTP `method`, and the request `path` may leave the
process. This is enforced and covered by tests.

### Framework integration

For web apps, prefer wiring the reporter into your framework's exception pipeline instead of the
global handler — you get the request `method`/`path`, the authenticated user, and a **source
window** on the failing line automatically. Each bridge filters out client-error (4xx) HTTP
exceptions (404s, validation, auth redirects) so the tracker isn't flooded with routing noise, and
re-raises / re-renders the error exactly as before.

All three read `CALLISTO_APP_ERROR_DSN` (required to activate) and `CALLISTO_ENVIRONMENT` (optional) —
**no `clientId`/`apiKey` needed**: error tracking is independent of the API client. The frameworks
themselves are *optional* (declared under composer `suggest`); a bridge is inert unless its
framework is installed.

**Laravel** — zero config. The SDK ships `Callisto\Sdk\Framework\Laravel\CallistoServiceProvider`,
auto-discovered via composer. Set `CALLISTO_APP_ERROR_DSN` in `.env` and you're done. It registers a
[`reportable`](https://laravel.com/docs/errors#reporting-exceptions) callback, so Laravel's own
logging and error pages are untouched. (Override the DSN/environment via a published `config/callisto.php`
with `dsn` / `environment` keys.)

**Symfony** — register the listener for the `kernel.exception` event:

```yaml
# config/services.yaml
services:
    Callisto\Sdk\Framework\Symfony\CallistoExceptionListener:
        tags:
            - { name: kernel.event_listener, event: kernel.exception, method: onKernelException }
```

**BowPHP** — report from your configured error handler (no middleware). Bow renders uncaught
exceptions through the class set as `error_handle` in `config/app.php`; add one line to its
`handle()`:

```php
use Callisto\Sdk\Framework\Bowphp\CallistoErrorHandler;

class ErrorHandle extends \Bow\Application\Exception\BaseErrorHandler
{
    public function handle($exception): mixed
    {
        CallistoErrorHandler::report($exception); // report to Callisto, then render as usual
        // ... your existing rendering
    }
}
```

It reads the current request automatically. Optionally configure the integration once at boot
(otherwise it builds from env): `CallistoErrorHandler::using(CallistoIntegration::fromEnv());`

**Any framework** — the framework-neutral core is reusable directly:

```php
use Callisto\Sdk\Integration\CallistoIntegration;

$callisto = CallistoIntegration::fromEnv();
try {
    $kernel->handle($request);
} catch (\Throwable $e) {
    $callisto->captureUnhandled($e, CallistoIntegration::request($method, $path), $user);
    throw $e;
}
```

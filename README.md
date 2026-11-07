# yesly-php

The official PHP library for the Yesly API.

> Status: 0.1.0, not published yet. Covers API version `2026-10-01`.

## Install

```bash
composer require yesly/yesly-php
```

PHP 8.1 or later with `ext-curl` and `ext-json`. No other dependencies.

## Usage

```php
$yesly = new \Yesly\Client(getenv('YESLY_API_KEY'));

$yesly->appointments->create([
    'external_id' => 'ORD-48213',
    'type' => 'delivery',
    'customer' => ['name' => 'Martha', 'phone' => '+34612345678'],
    'slot' => ['start' => '2026-11-13T09:00:00+01:00', 'end' => '2026-11-13T11:00:00+01:00'],
    'metadata' => ['order_id' => 'PV-18342', 'route_id' => 'BIO-07'],
]);
```

The key defaults to `YESLY_API_KEY`. Every resource of the API reference is a
property of the client: `appointments`, `imports`, `slots`, `appointmentTypes`,
`locations`, `webhookEndpoints`, `events`, `balance` and `analytics`.
Parameters and responses are arrays with the API's field names; each method's
PHPDoc lists the keys it takes.

## Webhooks

```php
try {
    $event = \Yesly\Webhook::constructEvent(
        file_get_contents('php://input'),
        $_SERVER['HTTP_YESLY_SIGNATURE'] ?? null,
        getenv('YESLY_WEBHOOK_SECRET'),
    );
} catch (\Yesly\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}
if ($event['type'] === 'appointment.confirmed') {
    $externalId = $event['data']['appointment']['external_id'];
}
```

Pass the raw body. Deliveries more than five minutes old are rejected, and
either signature is accepted during a secret roll. `Webhook::generateTestHeader`
signs a payload to test your handler.

## Lists

A list call returns its first `Page`. Iterating the page walks every page:

```php
$page = $yesly->events->list(['limit' => 50]);
$page->data; $page->hasMore; $page->nextPage();

foreach ($yesly->appointments->list(['status' => 'no_reply', 'metadata' => ['route_id' => 'BIO-07']]) as $apt) {
    echo $apt['external_id'], "\n";
}
```

## Errors

Failed requests throw a `Yesly\Exception\YeslyException` subclass:
`InvalidRequestException` (400, 422), `AuthenticationException` (401),
`PermissionDeniedException` (403), `NotFoundException` (404),
`ConflictException` (409), `RateLimitException` (429), `ApiException` (5xx) or
`ApiConnectionException` (no answer). Each has `status`, `type`, `errorCode`,
`param` and `requestId`.

## Retries, timeouts and idempotency

Network errors, timeouts, 408, 429 and 5xx are retried twice with backoff,
honouring `Retry-After`. POSTs that take an `Idempotency-Key` get one per call,
reused on every retry; pass your own in the last argument. Creating an
appointment is idempotent by `external_id` instead.

```php
new \Yesly\Client($key, ['timeout' => 10.0, 'max_retries' => 4]);
$yesly->appointments->cancel('apt_…', ['reason' => 'Shop closed'], ['idempotency_key' => 'cancel-ORD-48213']);
```

## Test mode

A `ysk_test_` key runs everything in test mode; `$yesly->mode()` says which.
Use the test phone numbers from the API reference.

## Development

```bash
composer install
composer test
composer analyse
```

`src/Service/*Service.php` (except `AbstractService.php`) and `src/Generated/`
are generated from the OpenAPI specification. Do not edit them: change
`yesly-openapi`, then, with both repos side by side,

```bash
(cd ../yesly-openapi && npm install)
composer generate
```

Smoke test against the real API with a test key:

```bash
YESLY_API_KEY=ysk_test_… php scripts/smoke.php
```

## Releasing

Bump `Version::VERSION` in `src/Version.php`, then push a tag `v<version>`;
Packagist reads the tag.

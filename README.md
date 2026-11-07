# yesly-php

The official PHP library for the Yesly API.

> Status: empty and not published. The package name and the example below match
> what the website shows; change both together if they change.

## Install

```bash
composer require yesly/yesly-php
```

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

## What it must cover

- Every resource in the API reference.
- Webhook signature verification (`Yesly-Signature`).
- Retries with idempotency keys, cursor pagination helpers.
- Test and live keys (`ysk_test_…`, `ysk_live_…`).

Resources are generated from `yesly-openapi`; hand-written code only for the
parts a spec cannot express.

## Development

To be defined: build, tests and release to Packagist.

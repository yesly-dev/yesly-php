# yesly-php

The official PHP library for the Yesly API.

> Status: 0.2.0, not published yet. Covers API version `2026-11-11`.

## Install

```bash
composer require yesly/yesly-php
```

PHP 8.1 or later with `ext-curl` and `ext-json`. No other dependencies.

## Usage

Yesly runs agents built for your company. The usual integration is two calls:
ask an agent to contact someone, and receive the outcome on a webhook.

```php
$yesly = new \Yesly\Client(getenv('YESLY_API_KEY'));

// Create the contact, or update it by your own id.
$contact = $yesly->contacts->create([
    'external_id' => 'customer-3391',
    'name' => 'Marta',
    'phone' => '+34600000001',
]);

// Ask the delivery agent to contact her: WhatsApp first, then a call.
$conversation = $yesly->conversations->create([
    'agent' => 'agt_8Hd2kQ4mZ1LpXy',
    'contact' => $contact['id'],
    'channels' => ['whatsapp', 'call'],
    'start_at' => '2026-12-01T10:00:00+01:00',
    'context' => ['order' => '8841', 'delivery_window' => '2026-12-02T09:00/13:00'],
], ['idempotency_key' => 'order-8841-delivery']);
$conversation['status']; // "scheduled"
```

`contact` also takes the data to find or create one
(`['external_id' => …, 'phone' => …, 'name' => …]`), so the first call is
optional. The key defaults to `YESLY_API_KEY`. Every resource of the API
reference is a property of the client: `agents`, `senders`, `contacts`,
`conversations`, `messages`, `templates`, `optOuts`, `analytics`, `balance`,
`webhookEndpoints` and `events`. Parameters and responses are arrays with the
API's field names; each method's PHPDoc lists the keys it takes.

## Integration checklist

These are the points an integration most often gets wrong; check them before
going live. The whole API reference is also in Markdown, for agents and other
tools: [llms-full.txt](https://yesly.dev/llms-full.txt) (index in
[llms.txt](https://yesly.dev/llms.txt), OpenAPI in
[openapi.yaml](https://yesly.dev/openapi.yaml)).

1. **Read your agent first** (`$yesly->agents->retrieve`). It declares the
   `outcomes` it can end with and the `context` it expects; a context that
   does not fit is a `422` `context_invalid` with the field in `param`.
2. **Use your own id as the contact's `external_id`.** Contacts are matched
   only by exact identifiers (`external_id`, then a phone, then an email), and
   `contacts->create` adds what is new to the one it finds. An identifier that
   belongs to another contact is a `409` `contact_identifier_taken`: merge the
   two first (`$yesly->contacts->merge`).
3. **Send an `Idempotency-Key` with every new conversation**, derived from your
   own data (`order-8841-delivery`). The library adds a random one to each
   call; your own makes a retry after a crash safe too.
4. **Send times with an offset** (`2026-12-01T10:00:00+01:00`) and phones in
   E.164. Live contacts must be in Spain, and not premium or special-rate
   numbers (`phone_not_allowed`).
5. **Handle the outcome on a webhook.** Verify the signature over the raw
   body, answer `2xx` within 10 seconds before doing slow work, and skip event
   ids you have already handled: a delivery can arrive more than once.
   `conversation.ended` carries the conversation with its `outcome`
   (`code` and `data`); `conversation.no_reply`, `conversation.cancelled` and
   `conversation.failed` close the others.
6. **Build and test with a `ysk_test_` key.** The test phone numbers choose
   what happens (`+34600000001` answers and the agent ends with its first
   outcome, `+34600000009` never answers; the full table is in the reference)
   and waits last seconds.
7. **Retry only what can succeed.** The library already retries network
   errors, `408`, `429` and `5xx`, with the same idempotency key. A `4xx`
   fails the same way again: fix the request using the error's `code` and
   `param`.
8. **Keep keys on the server.** A read-only key can list and retrieve but not
   write (`api_key_read_only`); a key limited to one agent gets `403`
   `api_key_agent_scope` on any other.

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
if ($event['type'] === 'conversation.ended') {
    $conversation = $event['data']['conversation'];
    $outcome = $conversation['outcome']; // ['code' => 'rescheduled', 'data' => ['delivery_window' => '2026-12-03T09:00/13:00']]
}
```

Pass the raw body. Deliveries more than five minutes old are rejected, and
either signature is accepted during a secret roll. `Webhook::generateTestHeader`
signs a payload to test your handler.
A delivery can arrive more than once, and a captured one stays valid for
those five minutes, so skip events whose `id` you have already handled.

## Lists

A list call returns its first `Page`. Iterating the page walks every page:

```php
$page = $yesly->events->list(['limit' => 50]);
$page->data; $page->hasMore; $page->nextPage();

foreach ($yesly->conversations->list(['agent' => 'agt_8Hd2kQ4mZ1LpXy', 'status' => 'no_reply']) as $conversation) {
    echo $conversation['id'], "\n";
}
```

## Errors

Failed requests throw a `Yesly\Exception\YeslyException` subclass:
`InvalidRequestException` (400, 422), `AuthenticationException` (401),
`PermissionDeniedException` (403), `NotFoundException` (404),
`ConflictException` (409), `RateLimitException` (429), `ApiException` (5xx) or
`ApiConnectionException` (no answer). Each has `status`, `type`, `errorCode`,
`param` and `requestId`.

```php
try {
    $yesly->conversations->create([…]);
} catch (\Yesly\Exception\InvalidRequestException $e) {
    if ($e->errorCode === 'context_invalid') { … }
}
```

## Retries, timeouts and idempotency

Network errors, timeouts, 408, 429 and 5xx are retried twice with backoff,
honouring `Retry-After`. POSTs that take an `Idempotency-Key` get one per call,
reused on every retry; pass your own in the last argument.

```php
new \Yesly\Client($key, ['timeout' => 10.0, 'max_retries' => 4]);
$yesly->conversations->cancel('cnv_7Qp4Ns2Xk9Lm3D', ['idempotency_key' => 'cancel-order-8841']);
```

## Test mode

A `ysk_test_` key runs everything in test mode; `$yesly->mode()` says which.
Use the test phone numbers from the API reference (`+34600000001` answers on
the first channel and the agent ends with its first outcome, and so on).

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

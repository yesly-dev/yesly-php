<?php

// Calls the real API with a test key: one call per resource, then cleans up.
//
//   YESLY_API_KEY=ysk_test_… php scripts/smoke.php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$yesly = new Yesly\Client();
if ($yesly->mode() !== 'test') {
    throw new RuntimeException('Use a ysk_test_ key.');
}
$tomorrow = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
$externalId = 'SDK-PHP-' . (int) (microtime(true) * 1000);
$step = static fn (string $name, mixed $value = '') => print("ok  {$name}: " . (is_bool($value) ? var_export($value, true) : $value) . "\n");

$step('balance->retrieve', $yesly->balance->retrieve()['object']);
$types = $yesly->appointmentTypes->list();
$step('appointmentTypes->list', implode(',', array_column($types->data, 'id')));
$step('locations->list', count($yesly->locations->list()->data));

$apt = $yesly->appointments->create([
    'external_id' => $externalId,
    'type' => $types->data[0]['id'],
    'customer' => ['name' => 'SDK smoke', 'phone' => '+34600000001'],
    'slot' => ['start' => "{$tomorrow}T10:00:00+01:00", 'end' => "{$tomorrow}T12:00:00+01:00"],
    'metadata' => ['source' => 'sdk-smoke'],
]);
$step('appointments->create', "{$apt['id']} {$apt['status']}");
$step('appointments->retrieve', $yesly->appointments->retrieve($apt['id'])['external_id']);
$n = 0;
foreach ($yesly->appointments->list(['metadata' => ['source' => 'sdk-smoke'], 'limit' => 1]) as $_) {
    if (++$n >= 3) {
        break;
    }
}
$step('appointments->list (auto-paging, limit 1)', $n);
$step('appointments->update', $yesly->appointments->update($apt['id'], ['metadata' => ['note' => 'updated']])['metadata']['note']);
$step('appointments->cancel', $yesly->appointments->cancel($apt['id'], ['reason' => 'SDK smoke test'])['status']);

$we = $yesly->webhookEndpoints->create(['url' => 'https://example.com/yesly-sdk-smoke', 'enabled_events' => ['appointment.confirmed']]);
$step('webhookEndpoints->create', $we['id']);
$step('webhookEndpoints->rollSecret', $yesly->webhookEndpoints->rollSecret($we['id'])['id']);
$step('webhookEndpoints->delete', $yesly->webhookEndpoints->delete($we['id'])['deleted']);

$events = $yesly->events->list(['limit' => 5]);
$step('events->list', implode(',', array_column($events->data, 'type')));
if ($events->data !== []) {
    $step('events->retrieve', $yesly->events->retrieve($events->data[0]['id'])['id']);
}
$step('balance->listTransactions', count($yesly->balance->listTransactions(['limit' => 5])->data));
$step('analytics->retrieveSummary', $yesly->analytics->retrieveSummary(['range' => '7d'])['appointments']);

try {
    $yesly->appointments->create(['external_id' => "{$externalId}-bad", 'customer' => ['name' => 'x', 'phone' => '612'], 'slot' => ['start' => "{$tomorrow}T10:00:00+01:00"]]);
} catch (Yesly\Exception\InvalidRequestException $e) {
    $step('validation error', get_class($e) . " {$e->status} {$e->errorCode} {$e->param} " . ($e->requestId ? 'with request id' : 'NO REQUEST ID'));
}

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
$externalId = 'SDK-PHP-' . (int) (microtime(true) * 1000);
$step = static fn (string $name, mixed $value = '') => print("ok  {$name}: " . (is_bool($value) ? var_export($value, true) : $value) . "\n");

$step('balance->retrieve', $yesly->balance->retrieve()['object']);
$agents = $yesly->agents->list();
$step('agents->list', implode(',', array_column($agents->data, 'id')));
$step('senders->list', count($yesly->senders->list()->data));
$step('templates->list', count($yesly->templates->list()->data));

$contact = $yesly->contacts->create(['external_id' => $externalId, 'name' => 'SDK smoke', 'phone' => '+34600000001', 'metadata' => ['source' => 'sdk-smoke']]);
$step('contacts->create', $contact['id']);
$step('contacts->create again (same contact)', $yesly->contacts->create(['external_id' => $externalId, 'phone' => '+34600000001'])['id'] === $contact['id']);
$step('contacts->retrieve', $yesly->contacts->retrieve($contact['id'])['external_id']);
$step('contacts->update', $yesly->contacts->update($contact['id'], ['metadata' => ['note' => 'updated']])['metadata']['note']);
$n = 0;
foreach ($yesly->contacts->list(['limit' => 1]) as $_) {
    if (++$n >= 3) {
        break;
    }
}
$step('contacts->list (auto-paging, limit 1)', $n);
$step('contacts->listMemory', count($yesly->contacts->listMemory($contact['id'])->data));

$agent = null;
foreach ($agents->data as $a) {
    if ($a['status'] === 'active') {
        $agent = $a;
        break;
    }
}
if ($agent !== null) {
    $params = ['agent' => $agent['id'], 'contact' => $contact['id'], 'channels' => [$agent['channels'][0]]];
    $cnv = $yesly->conversations->create($params, ['idempotency_key' => "{$externalId}-cnv"]);
    $step('conversations->create', "{$cnv['id']} {$cnv['status']}");
    $step('conversations->create again (replayed)', $yesly->conversations->create($params, ['idempotency_key' => "{$externalId}-cnv"])['id'] === $cnv['id']);
    $step('conversations->retrieve', $yesly->conversations->retrieve($cnv['id'])['status']);
    $step('conversations->list', count($yesly->conversations->list(['contact' => $contact['id']])->data));
    $step('conversations->listMessages', count($yesly->conversations->listMessages($cnv['id'])->data));
    $step('messages->list', count($yesly->messages->list(['conversation' => $cnv['id']])->data));
} else {
    $step('conversations->*', 'skipped: the account has no active agent');
}

$optOut = $yesly->optOuts->create(['channel' => 'sms', 'address' => '+34600000099']);
$step('optOuts->create', $optOut['id']);
$step('optOuts->delete', $yesly->optOuts->delete($optOut['id'])['deleted']);

$we = $yesly->webhookEndpoints->create(['url' => 'https://example.com/yesly-sdk-smoke', 'enabled_events' => ['conversation.ended']]);
$step('webhookEndpoints->create', $we['id']);
$step('webhookEndpoints->rollSecret', $yesly->webhookEndpoints->rollSecret($we['id'])['id']);
$step('webhookEndpoints->delete', $yesly->webhookEndpoints->delete($we['id'])['deleted']);

$events = $yesly->events->list(['limit' => 5]);
$step('events->list', implode(',', array_column($events->data, 'type')));
if ($events->data !== []) {
    $step('events->retrieve', $yesly->events->retrieve($events->data[0]['id'])['id']);
}
$step('balance->listTransactions', count($yesly->balance->listTransactions(['limit' => 5])->data));
$step('analytics->summary', $yesly->analytics->summary(['range' => '7d'])['conversations']);

try {
    $yesly->contacts->create(['external_id' => "{$externalId}-bad", 'phone' => '612']);
} catch (Yesly\Exception\InvalidRequestException $e) {
    $step('validation error', get_class($e) . " {$e->status} {$e->errorCode} {$e->param} " . ($e->requestId ? 'with request id' : 'NO REQUEST ID'));
}
$step('contacts->delete', $yesly->contacts->delete($contact['id'])['deleted']);

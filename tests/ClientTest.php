<?php

declare(strict_types=1);

namespace Yesly\Tests;

use PHPUnit\Framework\TestCase;
use Yesly\Client;
use Yesly\Exception\ApiConnectionException;
use Yesly\Exception\AuthenticationException;
use Yesly\Exception\ConflictException;
use Yesly\Exception\InvalidRequestException;
use Yesly\Exception\NotFoundException;
use Yesly\Exception\YeslyException;
use Yesly\Generated\Meta;
use Yesly\Version;

final class ClientTest extends TestCase
{
    private const CNV = ['id' => 'cnv_7Qp4Ns2Xk9Lm3D', 'object' => 'conversation', 'status' => 'scheduled'];

    private function client(FakeHttp $http): Client
    {
        return new class ('ysk_test_123', ['base_url' => 'https://api.test/v1', 'http_client' => $http]) extends Client {
            protected function sleep(float $seconds): void
            {
            }
        };
    }

    public function testCreatesAConversation(): void
    {
        $http = new FakeHttp([201, self::CNV]);
        $out = $this->client($http)->conversations->create([
            'agent' => 'agt_8Hd2kQ4mZ1LpXy',
            'contact' => ['external_id' => 'customer-3391', 'phone' => '+34600000001', 'name' => 'Marta'],
            'channels' => ['whatsapp', 'call'],
            'context' => ['order' => '8841'],
        ]);
        self::assertSame(self::CNV, $out);
        $c = $http->calls[0];
        self::assertSame('POST', $c['method']);
        self::assertSame('https://api.test/v1/conversations', $c['url']);
        self::assertSame('Bearer ysk_test_123', $c['headers']['Authorization']);
        self::assertSame(Meta::API_VERSION, $c['headers']['Yesly-Version']);
        self::assertStringStartsWith('yesly-php/' . Version::VERSION . ' ', $c['headers']['User-Agent']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $c['headers']['Idempotency-Key']);
        self::assertSame('+34600000001', $c['body']['contact']['phone']);
        self::assertSame(['whatsapp', 'call'], $c['body']['channels']);
    }

    public function testEncodesIdsAndQuery(): void
    {
        $http = new FakeHttp([200, self::CNV], [200, ['object' => 'list', 'url' => '/v1/conversations', 'has_more' => false, 'data' => []]], [200, ['id' => 'mem_1', 'deleted' => true]]);
        $y = $this->client($http);
        $y->conversations->retrieve('cnv/1');
        $y->conversations->list(['status' => 'no_reply', 'agent' => 'agt_8Hd2kQ4mZ1LpXy', 'limit' => 5, 'to' => null]);
        $y->contacts->deleteMemory('ctc_3Kf9Lq2Wm7Rt4B', 'mem/1');
        self::assertSame('https://api.test/v1/conversations/cnv%2F1', $http->calls[0]['url']);
        self::assertSame('https://api.test/v1/conversations?status=no_reply&agent=agt_8Hd2kQ4mZ1LpXy&limit=5', $http->calls[1]['url']);
        self::assertSame('DELETE', $http->calls[2]['method']);
        self::assertSame('https://api.test/v1/contacts/ctc_3Kf9Lq2Wm7Rt4B/memory/mem%2F1', $http->calls[2]['url']);
    }

    public function testEncodesNestedAndBooleanQueryValues(): void
    {
        self::assertSame('?a=true&metadata%5Bk%5D=v&channel=sms&channel=call', Client::queryString(['a' => true, 'metadata' => ['k' => 'v', 'x' => null], 'channel' => ['sms', 'call'], 'n' => null]));
    }

    public function testCancelSendsNoBody(): void
    {
        $http = new FakeHttp([200, self::CNV]);
        $this->client($http)->conversations->cancel('cnv_1');
        self::assertNull($http->calls[0]['body']);
        self::assertSame('POST', $http->calls[0]['method']);
        self::assertSame('https://api.test/v1/conversations/cnv_1/cancel', $http->calls[0]['url']);
    }

    public function testEmptyBodyIsAnObject(): void
    {
        $http = new FakeHttp([200, ['object' => 'webhook_test']]);
        $this->client($http)->webhookEndpoints->test('we_1');
        self::assertSame([], $http->calls[0]['body']);
        self::assertSame('https://api.test/v1/webhook_endpoints/we_1/test', $http->calls[0]['url']);
    }

    public function testOneIdempotencyKeyAcrossRetries(): void
    {
        $http = new FakeHttp([500, ['error' => ['type' => 'api_error', 'message' => 'boom']]], [503, [], ['retry-after' => '0']], [200, ['id' => 'ctc_1']]);
        $this->client($http)->contacts->create(['external_id' => 'customer-3391', 'phone' => '+34600000001']);
        self::assertCount(3, $http->calls);
        $keys = array_unique(array_map(static fn ($c) => $c['headers']['Idempotency-Key'], $http->calls));
        self::assertCount(1, $keys);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $keys[0]);
    }

    public function testUsesTheGivenIdempotencyKey(): void
    {
        $http = new FakeHttp([200, self::CNV]);
        $this->client($http)->conversations->cancel('cnv_7Qp4Ns2Xk9Lm3D', ['idempotency_key' => 'cancel-order-8841']);
        self::assertSame('cancel-order-8841', $http->calls[0]['headers']['Idempotency-Key']);
    }

    public function testRetriesThenGivesUpOnNetworkErrors(): void
    {
        $http = new FakeHttp([429, [], ['retry-after' => '0']], new ApiConnectionException('down'), new ApiConnectionException('down'));
        $this->expectException(ApiConnectionException::class);
        try {
            $this->client($http)->balance->retrieve();
        } finally {
            self::assertCount(3, $http->calls);
        }
    }

    public function testMapsErrorsAndDoesNotRetry4xx(): void
    {
        $http = new FakeHttp([422, ['error' => ['type' => 'invalid_request', 'code' => 'phone_invalid', 'param' => 'contact.phone', 'message' => 'Not E.164', 'request_id' => 'req_1']]]);
        try {
            $this->client($http)->conversations->create(['agent' => 'agt_8Hd2kQ4mZ1LpXy', 'contact' => ['phone' => '6'], 'channels' => ['call']]);
            self::fail('No exception');
        } catch (InvalidRequestException $e) {
            self::assertSame([422, 'phone_invalid', 'contact.phone', 'req_1', 'Not E.164'], [$e->status, $e->errorCode, $e->param, $e->requestId, $e->getMessage()]);
        }
        self::assertCount(1, $http->calls);
    }

    public function testStatusClasses(): void
    {
        $err = ['error' => ['type' => 'x', 'message' => 'm']];
        $y = $this->client(new FakeHttp([401, $err], [404, $err], [409, $err]));
        foreach ([AuthenticationException::class, NotFoundException::class, ConflictException::class] as $class) {
            try {
                $y->events->retrieve('evt_1');
                self::fail('No exception');
            } catch (YeslyException $e) {
                self::assertInstanceOf($class, $e);
            }
        }
    }

    public function testIteratingAPageWalksEveryPage(): void
    {
        $page = static fn (array $ids, bool $more) => [200, ['object' => 'list', 'url' => '/v1/events', 'has_more' => $more, 'data' => array_map(static fn ($id) => ['id' => $id], $ids)]];
        $http = new FakeHttp($page(['evt_3', 'evt_2'], true), $page(['evt_1'], false));
        $first = $this->client($http)->events->list(['limit' => 2]);
        self::assertSame(['evt_3', 'evt_2'], array_column($first->data, 'id'));
        $all = [];
        foreach ($first as $e) {
            $all[] = $e['id'];
        }
        self::assertSame(['evt_3', 'evt_2', 'evt_1'], $all);
        self::assertSame('https://api.test/v1/events?limit=2&starting_after=evt_2', $http->calls[1]['url']);
    }

    public function testTurnsWithoutIdComeInOnePage(): void
    {
        $turn = ['object' => 'turn', 'role' => 'contact', 'channel' => 'whatsapp', 'message' => 'msg_1', 'text' => 'Vale', 'at' => '2026-12-01T10:03:41+01:00'];
        $http = new FakeHttp([200, ['object' => 'list', 'url' => '/v1/conversations/cnv_1/messages', 'has_more' => false, 'data' => [$turn, $turn]]]);
        $texts = [];
        foreach ($this->client($http)->conversations->listMessages('cnv_1') as $t) {
            $texts[] = $t['text'];
        }
        self::assertSame(['Vale', 'Vale'], $texts);
        self::assertCount(1, $http->calls);
    }

    public function testKeyFromEnvironment(): void
    {
        putenv('YESLY_API_KEY=ysk_live_abc');
        try {
            self::assertSame('live', (new Client())->mode());
        } finally {
            putenv('YESLY_API_KEY');
        }
        $this->expectExceptionMessage('No API key');
        (new Client(null, ['http_client' => new FakeHttp()]))->balance->retrieve();
    }

    public function testEveryResourceIsAttached(): void
    {
        $y = new Client('ysk_test_1');
        foreach (['agents', 'senders', 'contacts', 'conversations', 'messages', 'templates', 'optOuts', 'analytics', 'balance', 'webhookEndpoints', 'events'] as $name) {
            self::assertIsObject($y->{$name});
        }
    }

    public function testBaseUrlMustBeHttpsExceptForLocalhost(): void
    {
        foreach (['http://localhost:4010', 'http://127.0.0.1:4010/v1', 'http://[::1]:4010'] as $baseUrl) {
            self::assertSame($baseUrl, (new Client('ysk_test_1', ['base_url' => $baseUrl]))->baseUrl);
        }
        $this->expectException(YeslyException::class);
        $this->expectExceptionMessage('must use https');
        new Client('ysk_test_1', ['base_url' => 'http://api.yesly.dev/v1']);
    }

    public function testDumpsDoNotShowTheApiKey(): void
    {
        $y = new Client('ysk_test_secretvalue');
        self::assertStringNotContainsString('secretvalue', print_r($y, true));
        ob_start();
        var_dump($y);
        self::assertStringNotContainsString('secretvalue', (string) ob_get_clean());
    }
}

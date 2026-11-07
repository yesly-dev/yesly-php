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
    private const APT = ['id' => 'apt_1', 'object' => 'appointment', 'external_id' => 'ORD-48213'];

    private function client(FakeHttp $http): Client
    {
        return new class ('ysk_test_123', ['base_url' => 'https://api.test/v1', 'http_client' => $http]) extends Client {
            protected function sleep(float $seconds): void
            {
            }
        };
    }

    public function testCreatesAnAppointment(): void
    {
        $http = new FakeHttp([201, self::APT]);
        $out = $this->client($http)->appointments->create([
            'external_id' => 'ORD-48213',
            'customer' => ['name' => 'Martha', 'phone' => '+34600000001'],
            'slot' => ['start' => '2026-11-13T09:00:00+01:00'],
        ]);
        self::assertSame(self::APT, $out);
        $c = $http->calls[0];
        self::assertSame('POST', $c['method']);
        self::assertSame('https://api.test/v1/appointments', $c['url']);
        self::assertSame('Bearer ysk_test_123', $c['headers']['Authorization']);
        self::assertSame(Meta::API_VERSION, $c['headers']['Yesly-Version']);
        self::assertStringStartsWith('yesly-php/' . Version::VERSION . ' ', $c['headers']['User-Agent']);
        self::assertArrayNotHasKey('Idempotency-Key', $c['headers']);
        self::assertSame('+34600000001', $c['body']['customer']['phone']);
    }

    public function testEncodesIdsAndQuery(): void
    {
        $http = new FakeHttp([200, self::APT], [200, ['object' => 'list', 'url' => '/v1/appointments', 'has_more' => false, 'data' => []]], [200, ['object' => 'list', 'data' => []]]);
        $y = $this->client($http);
        $y->appointments->retrieve('apt/1');
        $y->appointments->list(['status' => 'no_reply', 'metadata' => ['route_id' => 'BIO-07'], 'limit' => 5, 'to' => null]);
        $y->slots->list(['available' => true]);
        self::assertSame('https://api.test/v1/appointments/apt%2F1', $http->calls[0]['url']);
        self::assertSame('https://api.test/v1/appointments?status=no_reply&metadata%5Broute_id%5D=BIO-07&limit=5', $http->calls[1]['url']);
        self::assertSame('https://api.test/v1/slots?available=true', $http->calls[2]['url']);
    }

    public function testEmptyBodyIsAnObject(): void
    {
        $http = new FakeHttp([200, self::APT]);
        $this->client($http)->appointments->cancel('apt_1');
        self::assertSame([], $http->calls[0]['body']);
        self::assertSame('POST', $http->calls[0]['method']);
    }

    public function testOneIdempotencyKeyAcrossRetries(): void
    {
        $http = new FakeHttp([500, ['error' => ['type' => 'api_error', 'message' => 'boom']]], [503, [], ['retry-after' => '0']], [200, ['object' => 'list', 'data' => []]]);
        $this->client($http)->slots->create(['slots' => [['start' => '2026-11-13T09:00:00+01:00', 'type' => 'delivery']]]);
        self::assertCount(3, $http->calls);
        $keys = array_unique(array_map(static fn ($c) => $c['headers']['Idempotency-Key'], $http->calls));
        self::assertCount(1, $keys);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $keys[0]);
    }

    public function testUsesTheGivenIdempotencyKey(): void
    {
        $http = new FakeHttp([200, self::APT]);
        $this->client($http)->appointments->cancel('apt_1', ['reason' => 'Shop closed'], ['idempotency_key' => 'cancel-apt_1']);
        self::assertSame('cancel-apt_1', $http->calls[0]['headers']['Idempotency-Key']);
        self::assertSame(['reason' => 'Shop closed'], $http->calls[0]['body']);
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
        $http = new FakeHttp([400, ['error' => ['type' => 'invalid_request', 'code' => 'phone_invalid', 'param' => 'customer.phone', 'message' => 'Not E.164', 'request_id' => 'req_1']]]);
        try {
            $this->client($http)->appointments->create(['external_id' => 'x', 'customer' => ['phone' => '6'], 'slot' => ['start' => '2026-11-13T09:00:00+01:00']]);
            self::fail('No exception');
        } catch (InvalidRequestException $e) {
            self::assertSame([400, 'phone_invalid', 'customer.phone', 'req_1', 'Not E.164'], [$e->status, $e->errorCode, $e->param, $e->requestId, $e->getMessage()]);
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
        foreach (['appointments', 'imports', 'slots', 'appointmentTypes', 'locations', 'webhookEndpoints', 'events', 'balance', 'analytics'] as $name) {
            self::assertIsObject($y->{$name});
        }
    }
}

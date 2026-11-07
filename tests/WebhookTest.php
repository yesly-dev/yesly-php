<?php

declare(strict_types=1);

namespace Yesly\Tests;

use PHPUnit\Framework\TestCase;
use Yesly\Exception\SignatureVerificationException;
use Yesly\Webhook;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test';
    private const NOW = 1794474252;
    private const BODY = '{"id":"evt_1","object":"event","type":"appointment.confirmed","data":{"appointment":{"id":"apt_1"}}}';

    private static function sign(string $secret, int $t = self::NOW, string $payload = self::BODY): string
    {
        return hash_hmac('sha256', "{$t}.{$payload}", $secret);
    }

    public function testValidSignature(): void
    {
        $event = Webhook::constructEvent(self::BODY, 't=' . self::NOW . ',v1=' . self::sign(self::SECRET), self::SECRET, now: self::NOW);
        self::assertSame('appointment.confirmed', $event['type']);
    }

    public function testEitherSignatureDuringARoll(): void
    {
        $h = 't=' . self::NOW . ',v1=' . self::sign('whsec_old') . ',v1=' . self::sign(self::SECRET);
        self::assertSame('evt_1', Webhook::constructEvent(self::BODY, $h, self::SECRET, now: self::NOW)['id']);
    }

    /** @return iterable<string, array{0: string, 1: ?string, 2: string, 3: int, 4: string}> */
    public static function rejections(): iterable
    {
        $h = 't=' . self::NOW . ',v1=' . self::sign(self::SECRET);
        yield 'tampered body' => [self::BODY . ' ', $h, self::SECRET, self::NOW, 'matches'];
        yield 'wrong secret' => [self::BODY, $h, 'whsec_other', self::NOW, 'matches'];
        yield 'too old' => [self::BODY, $h, self::SECRET, self::NOW + 301, 'tolerance'];
        yield 'malformed' => [self::BODY, 'nonsense', self::SECRET, self::NOW, 'malformed'];
        yield 'missing' => [self::BODY, null, self::SECRET, self::NOW, 'missing'];
    }

    /** @dataProvider rejections */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejections')]
    public function testRejections(string $body, ?string $header, string $secret, int $now, string $message): void
    {
        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage($message);
        Webhook::constructEvent($body, $header, $secret, now: $now);
    }

    public function testToleranceZeroSkipsTheClockCheck(): void
    {
        $h = 't=' . self::NOW . ',v1=' . self::sign(self::SECRET);
        self::assertSame('evt_1', Webhook::constructEvent(self::BODY, $h, self::SECRET, 0, self::NOW + 3600)['id']);
    }

    public function testGenerateTestHeader(): void
    {
        self::assertSame('t=' . self::NOW . ',v1=' . self::sign(self::SECRET), Webhook::generateTestHeader(self::BODY, self::SECRET, self::NOW));
    }
}

<?php

declare(strict_types=1);

namespace Yesly;

use Yesly\Exception\SignatureVerificationException;

/** Webhook signature checks. They need the endpoint's signing secret (`whsec_…`), not an API key. */
final class Webhook
{
    /** Seconds a delivery's timestamp may differ from the local clock. */
    public const DEFAULT_TOLERANCE = 300;

    /**
     * Verifies a delivery and returns its event. Pass the raw body
     * (`file_get_contents('php://input')`), before any JSON decoding.
     *
     * @return array<string, mixed>
     *
     * @throws SignatureVerificationException
     */
    public static function constructEvent(string $payload, ?string $header, #[\SensitiveParameter] string $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): array
    {
        self::verifySignature($payload, $header, $secret, $tolerance, $now);
        $event = json_decode($payload, true);
        if (!\is_array($event)) {
            throw new SignatureVerificationException('The payload is not a JSON object.');
        }

        /** @var array<string, mixed> $event */
        return $event;
    }

    /**
     * Checks `Yesly-Signature` against the raw body. A `$tolerance` of 0 turns
     * the timestamp check off.
     *
     * @throws SignatureVerificationException
     */
    public static function verifySignature(string $payload, ?string $header, #[\SensitiveParameter] string $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): void
    {
        if ($header === null || $header === '') {
            throw new SignatureVerificationException('The Yesly-Signature header is missing.');
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (\count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't' && ctype_digit($kv[1])) {
                $timestamp = (int) $kv[1];
            } elseif ($kv[0] === 'v1') {
                $signatures[] = $kv[1];
            }
        }
        if ($timestamp === null || $signatures === []) {
            throw new SignatureVerificationException('The Yesly-Signature header is malformed.');
        }
        $now ??= time();
        if ($tolerance > 0 && abs($now - $timestamp) > $tolerance) {
            throw new SignatureVerificationException('The Yesly-Signature timestamp is outside the tolerance.');
        }
        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }
        throw new SignatureVerificationException('No signature in Yesly-Signature matches the payload.');
    }

    /** Builds a `Yesly-Signature` value, to test your own webhook handler. */
    public static function generateTestHeader(string $payload, string $secret, ?int $timestamp = null): string
    {
        $t = $timestamp ?? time();

        return "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$payload}", $secret);
    }
}

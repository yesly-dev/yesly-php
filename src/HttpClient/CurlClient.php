<?php

declare(strict_types=1);

namespace Yesly\HttpClient;

use Yesly\Exception\ApiConnectionException;

final class CurlClient implements HttpClientInterface
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ApiConnectionException('Could not start a cURL request.');
        }
        $responseHeaders = [];
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) (min($timeout, 10.0) * 1000),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $_, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (\count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return \strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $result = curl_exec($ch);
        if ($result === false) {
            $timedOut = curl_errno($ch) === CURLE_OPERATION_TIMEDOUT;
            $error = curl_error($ch);
            throw new ApiConnectionException($timedOut ? "The request timed out after {$timeout} s." : "Could not reach the Yesly API: {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return [$status, $responseHeaders, (string) $result];
    }
}

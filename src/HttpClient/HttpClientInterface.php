<?php

declare(strict_types=1);

namespace Yesly\HttpClient;

use Yesly\Exception\ApiConnectionException;

interface HttpClientInterface
{
    /**
     * Sends one request.
     *
     * @param non-empty-string $method
     * @param array<string, string> $headers
     *
     * @return array{0: int, 1: array<string, string>, 2: string} status, lower-case headers, body
     *
     * @throws ApiConnectionException when there is no answer
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): array;
}

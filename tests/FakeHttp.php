<?php

declare(strict_types=1);

namespace Yesly\Tests;

use Yesly\HttpClient\HttpClientInterface;

/** Answers from a queue and records each request. */
final class FakeHttp implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: mixed}> */
    public array $calls = [];

    /** @var list<array{0: int, 1: mixed, 2?: array<string, string>}|\Throwable> */
    private array $answers;

    /** @param array{0: int, 1: mixed, 2?: array<string, string>}|\Throwable ...$answers */
    public function __construct(array|\Throwable ...$answers)
    {
        $this->answers = array_values($answers);
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body === null ? null : json_decode($body, true)];
        $a = array_shift($this->answers);
        if ($a === null) {
            throw new \LogicException("Unexpected request {$method} {$url}");
        }
        if ($a instanceof \Throwable) {
            throw $a;
        }

        return [$a[0], ($a[2] ?? []) + ['content-type' => 'application/json'], $a[1] === null ? '' : (string) json_encode($a[1])];
    }
}

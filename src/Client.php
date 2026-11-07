<?php

declare(strict_types=1);

namespace Yesly;

use Yesly\Exception\ApiConnectionException;
use Yesly\Exception\YeslyException;
use Yesly\Generated\Meta;
use Yesly\Generated\Resources;
use Yesly\HttpClient\CurlClient;
use Yesly\HttpClient\HttpClientInterface;

/**
 * Client for the Yesly API. The resources (`$appointments`, `$slots`, …) are
 * generated from the OpenAPI specification.
 */
class Client
{
    use Resources;

    public readonly string $baseUrl;
    public readonly string $apiVersion;
    public readonly float $timeout;
    public readonly int $maxRetries;
    private readonly ?string $apiKey;
    private readonly HttpClientInterface $http;

    /**
     * @param string|null $apiKey defaults to the `YESLY_API_KEY` environment variable
     * @param array{base_url?: string, api_version?: string, timeout?: float, max_retries?: int, http_client?: HttpClientInterface} $options
     */
    public function __construct(?string $apiKey = null, array $options = [])
    {
        $env = static function (string $name): ?string {
            $v = getenv($name);

            return \is_string($v) && $v !== '' ? $v : null;
        };
        $this->apiKey = $apiKey ?? $env('YESLY_API_KEY');
        $this->baseUrl = rtrim($options['base_url'] ?? $env('YESLY_BASE_URL') ?? Meta::BASE_URL, '/');
        $this->apiVersion = $options['api_version'] ?? Meta::API_VERSION;
        $this->timeout = $options['timeout'] ?? 30.0;
        $this->maxRetries = $options['max_retries'] ?? 2;
        $this->http = $options['http_client'] ?? new CurlClient();
        $this->initResources();
    }

    /** `live` or `test`, from the key's prefix. */
    public function mode(): ?string
    {
        return match (true) {
            $this->apiKey !== null && str_starts_with($this->apiKey, 'ysk_live_') => 'live',
            $this->apiKey !== null && str_starts_with($this->apiKey, 'ysk_test_') => 'test',
            default => null,
        };
    }

    /**
     * @param non-empty-string $method
     * @param array<string, mixed>|null $query
     * @param array<string, mixed>|null $body
     * @param array{idempotency_key?: string, timeout?: float, max_retries?: int} $opts
     *
     * @return array<string, mixed>
     *
     * @throws YeslyException
     */
    public function request(string $method, string $path, ?array $query = null, ?array $body = null, bool $idempotent = false, array $opts = []): array
    {
        if ($this->apiKey === null) {
            throw new YeslyException('No API key. Pass one to new Client(…) or set YESLY_API_KEY.');
        }
        $url = $this->baseUrl . $path . self::queryString($query ?? []);
        $headers = [
            'Authorization' => "Bearer {$this->apiKey}",
            'Accept' => 'application/json',
            'Yesly-Version' => $this->apiVersion,
            'User-Agent' => 'yesly-php/' . Version::VERSION . ' php/' . \PHP_VERSION,
        ];
        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = json_encode($body === [] ? new \stdClass() : $body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
        }
        if ($idempotent) {
            $headers['Idempotency-Key'] = $opts['idempotency_key'] ?? self::uuid();
        }
        $timeout = $opts['timeout'] ?? $this->timeout;
        $maxRetries = $opts['max_retries'] ?? $this->maxRetries;

        for ($attempt = 0; ; ++$attempt) {
            try {
                [$status, $responseHeaders, $text] = $this->http->send($method, $url, $headers, $payload, $timeout);
            } catch (ApiConnectionException $e) {
                if ($attempt < $maxRetries) {
                    $this->sleep(self::backoff($attempt));
                    continue;
                }
                throw $e;
            }
            $data = $text === '' ? null : json_decode($text, true);
            if ($status >= 200 && $status < 300) {
                /** @var array<string, mixed> */
                return \is_array($data) ? $data : [];
            }
            $retryable = $status === 408 || $status === 429 || $status >= 500;
            if ($retryable && $attempt < $maxRetries) {
                $this->sleep(self::retryAfter($responseHeaders) ?? self::backoff($attempt));
                continue;
            }
            throw YeslyException::fromResponse($status, $data, $responseHeaders);
        }
    }

    /**
     * A list request. Iterate the returned page to get every item across pages.
     *
     * @param non-empty-string $method
     * @param array<string, mixed>|null $query
     * @param array{idempotency_key?: string, timeout?: float, max_retries?: int} $opts
     */
    public function list(string $method, string $path, ?array $query = null, array $opts = []): Page
    {
        $fetch = function (?string $startingAfter) use (&$fetch, $method, $path, $query, $opts): Page {
            $q = $query ?? [];
            if ($startingAfter !== null) {
                $q['starting_after'] = $startingAfter;
            }

            return new Page($this->request($method, $path, $q, null, false, $opts), \Closure::fromCallable($fetch));
        };

        return $fetch(null);
    }

    /** Waits before a retry. */
    protected function sleep(float $seconds): void
    {
        usleep((int) ($seconds * 1_000_000));
    }

    /**
     * `a=1&metadata[k]=v`; nulls are left out, booleans are `true`/`false`.
     *
     * @param array<string, mixed> $query
     */
    public static function queryString(array $query): string
    {
        $scalar = static fn (mixed $v): string => \is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        $pairs = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (\is_array($value) && array_is_list($value)) {
                foreach ($value as $v) {
                    $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode($scalar($v));
                }
            } elseif (\is_array($value)) {
                foreach ($value as $k => $v) {
                    if ($v !== null) {
                        $pairs[] = rawurlencode("{$key}[{$k}]") . '=' . rawurlencode($scalar($v));
                    }
                }
            } else {
                $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode($scalar($value));
            }
        }

        return $pairs === [] ? '' : '?' . implode('&', $pairs);
    }

    /** @param array<string, string> $headers */
    private static function retryAfter(array $headers): ?float
    {
        $v = $headers['retry-after'] ?? null;
        if ($v === null || !is_numeric($v)) {
            return null;
        }
        $s = (float) $v;

        return $s >= 0 && $s <= 60 ? $s : null;
    }

    private static function backoff(int $attempt): float
    {
        $base = min(0.5 * 2 ** $attempt, 8.0);

        return $base / 2 + mt_rand() / mt_getrandmax() * $base / 2;
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = \chr((\ord($b[6]) & 0x0F) | 0x40);
        $b[8] = \chr((\ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}

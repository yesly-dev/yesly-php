<?php

declare(strict_types=1);

namespace Yesly\Exception;

/** Base class of every exception this library throws. */
class YeslyException extends \RuntimeException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        string $message,
        /** HTTP status, when the API answered. */
        public readonly ?int $status = null,
        /** Error class from the API, such as `invalid_request`. */
        public readonly ?string $type = null,
        /** Machine-readable reason, such as `phone_invalid`. */
        public readonly ?string $errorCode = null,
        /** The parameter at fault, such as `customer.phone`. */
        public readonly ?string $param = null,
        /** `Request-Id` of the failed request; include it when you contact support. */
        public readonly ?string $requestId = null,
        public readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    /**
     * @param array<string, string> $headers lower-case names
     */
    public static function fromResponse(int $status, mixed $body, array $headers): self
    {
        $e = \is_array($body) && \is_array($body['error'] ?? null) ? $body['error'] : [];
        $str = static fn (mixed $v): ?string => \is_string($v) ? $v : null;
        $message = $str($e['message'] ?? null) ?? "The Yesly API answered {$status}.";
        $class = match (true) {
            $status === 401 => AuthenticationException::class,
            $status === 403 => PermissionDeniedException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 429 => RateLimitException::class,
            $status >= 500 => ApiException::class,
            default => InvalidRequestException::class,
        };

        return new $class(
            $message,
            $status,
            $str($e['type'] ?? null),
            $str($e['code'] ?? null),
            $str($e['param'] ?? null),
            $str($e['request_id'] ?? null) ?? ($headers['request-id'] ?? null),
            $headers,
        );
    }
}

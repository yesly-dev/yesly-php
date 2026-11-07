<?php

declare(strict_types=1);

namespace Yesly\Exception;

/** 429: too many requests. Retried automatically before this is thrown. */
class RateLimitException extends YeslyException
{
}

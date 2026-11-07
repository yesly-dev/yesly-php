<?php

declare(strict_types=1);

namespace Yesly\Exception;

/** 403: the key cannot reach this resource, or the IP is not allowed. */
class PermissionDeniedException extends YeslyException
{
}

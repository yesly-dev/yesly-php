<?php

declare(strict_types=1);

namespace Yesly\Exception;

/** 409: such as an `external_id` already used with different data. */
class ConflictException extends YeslyException
{
}

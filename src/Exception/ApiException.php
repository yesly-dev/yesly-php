<?php

declare(strict_types=1);

namespace Yesly\Exception;

/** 5xx: something went wrong on Yesly's side. Retried automatically before this is thrown. */
class ApiException extends YeslyException
{
}

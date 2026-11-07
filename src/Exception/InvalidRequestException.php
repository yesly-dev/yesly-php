<?php

declare(strict_types=1);

namespace Yesly\Exception;

/** 400 and other 4xx answers: something in the request needs fixing. */
class InvalidRequestException extends YeslyException
{
}

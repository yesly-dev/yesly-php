<?php

declare(strict_types=1);

namespace Yesly\Exception;

/** A webhook delivery whose `Yesly-Signature` does not match, or is too old. */
class SignatureVerificationException extends YeslyException
{
}

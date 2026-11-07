<?php

declare(strict_types=1);

namespace Yesly\Service;

use Yesly\Client;

abstract class AbstractService
{
    public function __construct(protected readonly Client $client)
    {
    }
}

<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Exceptions;

final class TimeoutException extends ConnectionException
{
    public function __construct(public readonly int $timeoutSeconds)
    {
        parent::__construct("ViaPost request timed out after {$timeoutSeconds} seconds.");
    }
}

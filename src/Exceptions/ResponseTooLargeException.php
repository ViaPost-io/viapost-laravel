<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Exceptions;

final class ResponseTooLargeException extends ViaPostException
{
    public function __construct(public readonly int $maxResponseBytes)
    {
        parent::__construct("ViaPost response exceeded {$maxResponseBytes} bytes.");
    }
}

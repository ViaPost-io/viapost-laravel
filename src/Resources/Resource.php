<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Resources;

use InvalidArgumentException;
use ViaPost\Laravel\Client;

abstract readonly class Resource
{
    public function __construct(protected Client $client) {}

    protected function pathParam(string $name, string $value): string
    {
        if ($value === '' || $value === '.' || $value === '..') {
            throw new InvalidArgumentException("{$name} must be a non-empty path value other than '.' or '..'.");
        }

        return rawurlencode($value);
    }
}

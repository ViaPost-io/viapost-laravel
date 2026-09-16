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

    /** @return array{Idempotency-Key: string} */
    protected function idempotencyHeaders(string $value): array
    {
        if (preg_match('/\A[\x21-\x7E]{1,255}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('ViaPost idempotency key must contain between 1 and 255 visible ASCII characters.');
        }

        return ['Idempotency-Key' => $value];
    }
}

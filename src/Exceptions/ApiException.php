<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Exceptions;

final class ApiException extends ViaPostException
{
    /**
     * @param  array<string, list<string>>  $headers
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $method,
        public readonly string $url,
        public readonly mixed $body,
        public readonly ?string $requestId,
        public readonly array $headers,
    ) {
        parent::__construct($message, $status);
    }
}

<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Resources;

use InvalidArgumentException;

final readonly class ContactsResource extends Resource
{
    private const MAX_IMPORT_BYTES = 2_097_152;

    /** @return array<string, mixed> */
    public function import(string $csv): array
    {
        if (strlen($csv) > self::MAX_IMPORT_BYTES) {
            throw new InvalidArgumentException('ViaPost contacts import CSV must not exceed 2 MiB.');
        }

        /** @var array<string, mixed> */
        return $this->client->requestJsonWithRawBody(
            'POST',
            '/v1/contacts/import',
            $csv,
            'text/csv; charset=UTF-8',
        );
    }
}

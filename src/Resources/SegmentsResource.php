<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Resources;

use InvalidArgumentException;

final readonly class SegmentsResource extends Resource
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function preview(array $input): array
    {
        if (array_diff(array_keys($input), ['definition', 'limit']) !== []) {
            throw new InvalidArgumentException('ViaPost segment preview contains unsupported properties.');
        }
        if (! isset($input['definition']) || ! is_array($input['definition']) || $input['definition'] === []) {
            throw new InvalidArgumentException('ViaPost segment preview definition must be a non-empty object.');
        }
        if (isset($input['limit']) && (! is_int($input['limit']) || $input['limit'] < 1 || $input['limit'] > 50)) {
            throw new InvalidArgumentException('ViaPost segment preview limit must be an integer between 1 and 50.');
        }

        /** @var array<string, mixed> */
        return $this->client->request('POST', '/v1/segments/preview', body: $input);
    }
}

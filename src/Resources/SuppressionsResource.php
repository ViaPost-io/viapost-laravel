<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Resources;

use InvalidArgumentException;

final readonly class SuppressionsResource extends Resource
{
    private const MAX_IMPORT_BYTES = 2_097_152;

    /**
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', '/v1/suppressions', query: $query);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('POST', '/v1/suppressions', body: $input);
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @return array<string, mixed>
     */
    public function retrieve(string $suppressionId, array $query = []): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', $this->path($suppressionId), query: $query);
    }

    /** @return array<string, mixed> */
    public function import(string $csv): array
    {
        if (strlen($csv) > self::MAX_IMPORT_BYTES) {
            throw new InvalidArgumentException('ViaPost suppression CSV must not exceed 2 MiB.');
        }

        /** @var array<string, mixed> */
        return $this->client->requestJsonWithRawBody(
            'POST',
            '/v1/suppressions/import',
            $csv,
            'text/csv; charset=UTF-8',
        );
    }

    /** @param array<string, scalar|list<scalar>|null> $query */
    public function export(array $query = []): string
    {
        return $this->client->requestRaw('GET', '/v1/suppressions/export', $query, 'text/csv');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function release(string $suppressionId, array $input): array
    {
        $allowed = ['expected_version', 'acknowledge', 'justification'];
        if (array_diff(array_keys($input), $allowed) !== [] || count($input) !== count($allowed)) {
            throw new InvalidArgumentException('ViaPost suppression release must contain only expected_version, acknowledge, and justification.');
        }
        if (! isset($input['expected_version']) || ! is_int($input['expected_version']) || $input['expected_version'] < 1) {
            throw new InvalidArgumentException('ViaPost suppression expected_version must be an integer of at least 1.');
        }
        if (($input['acknowledge'] ?? null) !== true) {
            throw new InvalidArgumentException('ViaPost suppression release must explicitly acknowledge the operation.');
        }

        $justification = $input['justification'] ?? null;
        $justificationLength = is_string($justification) ? preg_match_all('/./us', $justification) : false;
        if ($justificationLength === false || $justificationLength < 10 || $justificationLength > 500) {
            throw new InvalidArgumentException('ViaPost suppression justification must contain between 10 and 500 characters.');
        }

        /** @var array<string, mixed> */
        return $this->client->request('POST', $this->path($suppressionId).'/release', body: $input);
    }

    private function path(string $suppressionId): string
    {
        return '/v1/suppressions/'.$this->pathParam('suppressionId', $suppressionId);
    }
}

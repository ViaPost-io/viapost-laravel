<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Resources;

use InvalidArgumentException;

final readonly class TemplatesResource extends Resource
{
    /**
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', '/v1/templates', query: $query);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('POST', '/v1/templates', body: $input);
    }

    /** @return array<string, mixed> */
    public function retrieve(string $templateId): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', $this->path($templateId));
    }

    public function delete(string $templateId): null
    {
        $this->client->request('DELETE', $this->path($templateId));

        return null;
    }

    public function archive(string $templateId): null
    {
        $this->client->request('POST', $this->path($templateId).'/archive');

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createAsset(string $templateId, array $input): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('POST', $this->path($templateId).'/assets', body: $input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function updateDraft(string $templateId, array $input): array
    {
        if (isset($input['variables']) && (! is_array($input['variables']) || count($input['variables']) > 100)) {
            throw new InvalidArgumentException('ViaPost template variables must contain at most 100 items.');
        }
        $hasVersion = array_key_exists('expected_version_id', $input);
        $hasUpdatedAt = array_key_exists('expected_updated_at', $input);
        if ($hasVersion !== $hasUpdatedAt) {
            throw new InvalidArgumentException('ViaPost expected_version_id and expected_updated_at must be provided together.');
        }

        /** @var array<string, mixed> */
        return $this->client->request('PATCH', $this->path($templateId).'/draft', body: $input);
    }

    /** @return array<string, mixed> */
    public function duplicate(string $templateId): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('POST', $this->path($templateId).'/duplicate');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function preview(string $templateId, array $input = []): array
    {
        if (isset($input['variables']) && (! is_array($input['variables']) || count($input['variables']) > 100)) {
            throw new InvalidArgumentException('ViaPost preview variables must contain at most 100 properties.');
        }

        /** @var array<string, mixed> */
        return $this->client->request('POST', $this->path($templateId).'/preview', body: $input);
    }

    /**
     * @param  array<string, mixed>|null  $input
     * @return array<string, mixed>
     */
    public function publish(string $templateId, ?array $input = null): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('POST', $this->path($templateId).'/publish', body: $input);
    }

    /** @return array<string, mixed> */
    public function versions(string $templateId): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', $this->path($templateId).'/versions');
    }

    /** @return array<string, mixed> */
    public function version(string $templateId, string $versionId): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', $this->path($templateId).'/versions/'.$this->pathParam('versionId', $versionId));
    }

    /**
     * @param  array<string, mixed>|null  $input
     * @return array<string, mixed>
     */
    public function revert(string $templateId, string $versionId, ?array $input = null): array
    {
        /** @var array<string, mixed> */
        return $this->client->request(
            'POST',
            $this->path($templateId).'/versions/'.$this->pathParam('versionId', $versionId).'/revert',
            body: $input,
        );
    }

    private function path(string $templateId): string
    {
        return '/v1/templates/'.$this->pathParam('templateId', $templateId);
    }
}

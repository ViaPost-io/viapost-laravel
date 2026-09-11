<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Resources;

final readonly class AutomationsResource extends Resource
{
    /**
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', '/v1/automations', query: $query);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('POST', '/v1/automations', body: $input);
    }

    /** @return array<string, mixed> */
    public function retrieve(string $automationId): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', $this->path($automationId));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(string $automationId, array $input): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('PATCH', $this->path($automationId), body: $input);
    }

    public function delete(string $automationId): null
    {
        $this->client->request('DELETE', $this->path($automationId));

        return null;
    }

    /** @return array<string, mixed> */
    public function activate(string $automationId): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('POST', $this->path($automationId).'/activate');
    }

    /** @return array<string, mixed> */
    public function disable(string $automationId): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('POST', $this->path($automationId).'/disable');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function updateDraft(string $automationId, array $input): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('PATCH', $this->path($automationId).'/draft', body: $input);
    }

    /** @return array<string, mixed> */
    public function duplicate(string $automationId): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('POST', $this->path($automationId).'/duplicate');
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @return array<string, mixed>
     */
    public function runs(string $automationId, array $query = []): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', $this->path($automationId).'/runs', query: $query);
    }

    /** @return array<string, mixed> */
    public function run(string $automationId, string $runId): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', $this->runPath($automationId, $runId));
    }

    public function cancelRun(string $automationId, string $runId): null
    {
        $this->client->request('POST', $this->runPath($automationId, $runId).'/cancel');

        return null;
    }

    private function path(string $automationId): string
    {
        return '/v1/automations/'.$this->pathParam('automationId', $automationId);
    }

    private function runPath(string $automationId, string $runId): string
    {
        return $this->path($automationId).'/runs/'.$this->pathParam('runId', $runId);
    }
}

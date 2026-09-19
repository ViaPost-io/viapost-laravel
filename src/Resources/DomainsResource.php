<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Resources;

final readonly class DomainsResource extends Resource
{
    /** @return array<string, mixed> */
    public function list(): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', '/v1/domains');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('POST', '/v1/domains', body: $input);
    }

    /** @return array<string, mixed> */
    public function retrieve(string $domainId): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', '/v1/domains/'.$this->pathParam('domainId', $domainId));
    }

    public function delete(string $domainId): null
    {
        $this->client->request('DELETE', '/v1/domains/'.$this->pathParam('domainId', $domainId));

        return null;
    }

    /** @return array<string, mixed> */
    public function dns(string $domainId): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', '/v1/domains/'.$this->pathParam('domainId', $domainId).'/dns');
    }

    /** @return array<string, mixed> */
    public function health(string $domainId): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', '/v1/domains/'.$this->pathParam('domainId', $domainId).'/health');
    }

    /** @return array<string, mixed> */
    public function inbound(string $domainId): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', '/v1/domains/'.$this->pathParam('domainId', $domainId).'/inbound');
    }

    /** @return array<string, mixed> */
    public function verify(string $domainId): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('POST', '/v1/domains/'.$this->pathParam('domainId', $domainId).'/verify');
    }

    /** @return array<string, mixed> */
    public function rotateDkim(string $domainId): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('POST', '/v1/domains/'.$this->pathParam('domainId', $domainId).'/dkim/rotate');
    }
}

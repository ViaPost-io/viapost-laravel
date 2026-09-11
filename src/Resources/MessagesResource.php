<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Resources;

final readonly class MessagesResource extends Resource
{
    /**
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', '/v1/messages', query: $query);
    }

    /** @return array<string, mixed> */
    public function retrieve(string $messageId): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', '/v1/messages/'.$this->pathParam('messageId', $messageId));
    }

    /** @return array<string, mixed> */
    public function events(string $messageId): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', '/v1/messages/'.$this->pathParam('messageId', $messageId).'/events');
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @return array<string, mixed>
     */
    public function engagement(array $query = []): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', '/v1/messages/engagement', query: $query);
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @return array<string, mixed>
     */
    public function metrics(array $query = []): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', '/v1/messages/metrics', query: $query);
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @return array<string, mixed>
     */
    public function timeseries(array $query = []): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', '/v1/messages/timeseries', query: $query);
    }
}

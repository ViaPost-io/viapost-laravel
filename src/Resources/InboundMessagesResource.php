<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Resources;

final readonly class InboundMessagesResource extends Resource
{
    /**
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', '/v1/inbound-messages', query: $query);
    }

    /** @return array<string, mixed> */
    public function retrieve(string $messageId): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', $this->path($messageId));
    }

    public function raw(string $messageId): string
    {
        return $this->client->requestRaw('GET', $this->path($messageId).'/raw', accept: 'message/rfc822');
    }

    private function path(string $messageId): string
    {
        return '/v1/inbound-messages/'.$this->pathParam('messageId', $messageId);
    }
}

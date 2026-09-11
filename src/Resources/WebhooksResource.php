<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Resources;

use InvalidArgumentException;

final readonly class WebhooksResource extends Resource
{
    /** @return array<string, mixed> */
    public function list(): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', '/v1/webhooks');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $url = $input['url'] ?? null;
        $parts = is_string($url) ? parse_url($url) : false;
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        if (! is_array($parts) || ! in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            throw new InvalidArgumentException('ViaPost webhook url must be an absolute HTTP(S) URL.');
        }
        if (! isset($input['event_types']) || ! is_array($input['event_types']) || count($input['event_types']) < 1) {
            throw new InvalidArgumentException('ViaPost webhook event_types must contain at least one item.');
        }

        /** @var array<string, mixed> */
        return $this->client->request('POST', '/v1/webhooks', body: $input);
    }

    public function delete(string $webhookId): null
    {
        $this->client->request('DELETE', '/v1/webhooks/'.$this->pathParam('webhookId', $webhookId));

        return null;
    }
}

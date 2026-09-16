<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Resources;

use InvalidArgumentException;
use ViaPost\Laravel\Responses\WebhookSecretResponse;

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
     */
    public function create(array $input): WebhookSecretResponse
    {
        if (array_diff(array_keys($input), ['url', 'event_types']) !== []) {
            throw new InvalidArgumentException('ViaPost webhook create contains unsupported properties.');
        }
        $this->validateUrl($input['url'] ?? null, required: true);
        $this->validateEventTypes($input['event_types'] ?? null);

        /** @var array<string, mixed> */
        $response = $this->client->request('POST', '/v1/webhooks', body: $input);

        return new WebhookSecretResponse($response);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(string $webhookId, array $input): array
    {
        $allowed = ['expected_version', 'enabled', 'event_types', 'max_attempts'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new InvalidArgumentException('ViaPost webhook update contains unsupported properties.');
        }
        if (count($input) < 2) {
            throw new InvalidArgumentException('ViaPost webhook update must include expected_version and at least one change.');
        }
        if (! isset($input['expected_version']) || ! is_int($input['expected_version']) || $input['expected_version'] < 1) {
            throw new InvalidArgumentException('ViaPost webhook expected_version must be an integer of at least 1.');
        }
        if (array_key_exists('enabled', $input) && ! is_bool($input['enabled'])) {
            throw new InvalidArgumentException('ViaPost webhook enabled must be boolean.');
        }
        if (array_key_exists('event_types', $input)) {
            $this->validateEventTypes($input['event_types']);
        }
        if (array_key_exists('max_attempts', $input)
            && (! is_int($input['max_attempts']) || $input['max_attempts'] < 1 || $input['max_attempts'] > 20)) {
            throw new InvalidArgumentException('ViaPost webhook max_attempts must be an integer between 1 and 20.');
        }

        /** @var array<string, mixed> */
        return $this->client->request('PATCH', $this->path($webhookId), body: $input);
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @return array<string, mixed>
     */
    public function deliveries(string $webhookId, array $query = []): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', $this->path($webhookId).'/deliveries', query: $query);
    }

    /** @return array<string, mixed> */
    public function delivery(string $webhookId, string $deliveryId): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', $this->deliveryPath($webhookId, $deliveryId));
    }

    /** @return array<string, mixed> */
    public function replay(string $webhookId, string $deliveryId, string $idempotencyKey): array
    {
        /** @var array<string, mixed> */
        return $this->client->requestJsonWithRawBody(
            'POST',
            $this->deliveryPath($webhookId, $deliveryId).'/replay',
            '{}',
            'application/json',
            headers: $this->idempotencyHeaders($idempotencyKey),
        );
    }

    /** @return array<string, mixed> */
    public function test(string $webhookId, string $idempotencyKey): array
    {
        /** @var array<string, mixed> */
        return $this->client->requestJsonWithRawBody(
            'POST',
            $this->path($webhookId).'/test',
            '{}',
            'application/json',
            headers: $this->idempotencyHeaders($idempotencyKey),
        );
    }

    public function rotateSecret(string $webhookId, string $idempotencyKey): WebhookSecretResponse
    {
        /** @var array<string, mixed> */
        $response = $this->client->requestJsonWithRawBody(
            'POST',
            $this->path($webhookId).'/secret/rotate',
            '{}',
            'application/json',
            headers: $this->idempotencyHeaders($idempotencyKey),
        );

        return new WebhookSecretResponse($response);
    }

    public function delete(string $webhookId): null
    {
        $this->client->request('DELETE', $this->path($webhookId));

        return null;
    }

    private function path(string $webhookId): string
    {
        return '/v1/webhooks/'.$this->pathParam('webhookId', $webhookId);
    }

    private function deliveryPath(string $webhookId, string $deliveryId): string
    {
        return $this->path($webhookId).'/deliveries/'.$this->pathParam('deliveryId', $deliveryId);
    }

    private function validateUrl(mixed $url, bool $required = false): void
    {
        if ($url === null && ! $required) {
            return;
        }

        $parts = is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false ? parse_url($url) : false;
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        if (! is_array($parts) || $scheme !== 'https' || empty($parts['host'])) {
            throw new InvalidArgumentException('ViaPost webhook url must be an absolute HTTPS URL.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('ViaPost webhook url must not contain credentials or a fragment.');
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new InvalidArgumentException('ViaPost webhook url must target a public host.');
        }

        $literalIp = filter_var($host, FILTER_VALIDATE_IP);
        if ($literalIp === false && $this->looksLikeLegacyIpv4($host)) {
            throw new InvalidArgumentException('ViaPost webhook url must not use ambiguous numeric IP notation.');
        }

        if ($literalIp !== false
            && (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
                || $this->isMulticastIp($host))) {
            throw new InvalidArgumentException('ViaPost webhook url must not target a non-public IP address.');
        }
    }

    private function looksLikeLegacyIpv4(string $host): bool
    {
        return preg_match('/^(?:0x[0-9a-f]+|\d+)(?:\.(?:0x[0-9a-f]+|\d+)){0,3}$/i', $host) === 1;
    }

    private function validateEventTypes(mixed $eventTypes): void
    {
        if (! is_array($eventTypes) || ! array_is_list($eventTypes) || count($eventTypes) < 1) {
            throw new InvalidArgumentException('ViaPost webhook event_types must contain at least one item.');
        }

        foreach ($eventTypes as $eventType) {
            if (! is_string($eventType) || $eventType === '') {
                throw new InvalidArgumentException('ViaPost webhook event_types must contain non-empty strings.');
            }
        }

        if (count(array_unique($eventTypes, SORT_STRING)) !== count($eventTypes)) {
            throw new InvalidArgumentException('ViaPost webhook event_types must not contain duplicates.');
        }
    }

    private function isMulticastIp(string $host): bool
    {
        $packed = inet_pton($host);
        if ($packed === false) {
            return false;
        }

        $firstByte = ord($packed[0]);

        return (strlen($packed) === 4 && $firstByte >= 224 && $firstByte <= 239)
            || (strlen($packed) === 16 && $firstByte === 255);
    }
}

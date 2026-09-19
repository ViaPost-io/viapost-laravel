<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Resources;

use InvalidArgumentException;

final readonly class SendResource extends Resource
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(array $input, ?string $idempotencyKey = null): array
    {
        if ($idempotencyKey !== null) {
            $this->validateIdempotencyKey($idempotencyKey);
        }
        $this->validateInput($input);
        $headers = $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey];
        /** @var array<string, mixed> $result */
        $result = $this->client->request('POST', '/v1/send', body: $input, headers: $headers);

        $result['accepted'] ??= [];
        $result['rejected'] ??= [];

        return $result;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function batch(array $input): array
    {
        if (array_diff(array_keys($input), ['messages']) !== []) {
            throw new InvalidArgumentException('ViaPost batch send contains unsupported properties.');
        }
        $messages = $input['messages'] ?? null;
        if (! is_array($messages) || ! array_is_list($messages) || count($messages) < 1 || count($messages) > 100) {
            throw new InvalidArgumentException('ViaPost batch send messages must contain between 1 and 100 items.');
        }
        foreach ($messages as $message) {
            if (! is_array($message)) {
                throw new InvalidArgumentException('ViaPost batch send items must be objects.');
            }
            $this->assertObject($message, 'item');
            if (array_diff(array_keys($message), ['idempotency_key', 'request']) !== []) {
                throw new InvalidArgumentException('ViaPost batch send items must contain only idempotency_key and request.');
            }
            $idempotencyKey = $message['idempotency_key'] ?? null;
            if (! is_string($idempotencyKey)) {
                throw new InvalidArgumentException('ViaPost batch send item idempotency_key must be a string.');
            }
            $this->validateIdempotencyKey($idempotencyKey);
            $request = $message['request'] ?? null;
            if (! is_array($request)) {
                throw new InvalidArgumentException('ViaPost batch send item request must be an object.');
            }
            $this->assertObject($request, 'item request');
            $this->validateInput($request);
        }

        /** @var array<string, mixed> */
        return $this->client->request('POST', '/v1/send/batch', body: $input);
    }

    private function validateIdempotencyKey(string $value): void
    {
        if (preg_match('/\A[\x21-\x7E]{1,255}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('ViaPost idempotency key must contain between 1 and 255 visible ASCII characters.');
        }
    }

    /**
     * @param  array<mixed>  $value
     *
     * @phpstan-assert array<string, mixed> $value
     */
    private function assertObject(array $value, string $field): void
    {
        foreach ($value as $key => $_) {
            if (! is_string($key)) {
                throw new InvalidArgumentException("ViaPost batch send {$field} must be an object.");
            }
        }
    }

    /** @param array<string, mixed> $input */
    private function validateInput(array $input): void
    {
        $to = $input['to'] ?? null;
        if (! is_array($to) || count($to) < 1 || count($to) > 50) {
            throw new InvalidArgumentException('ViaPost to must contain between 1 and 50 recipients.');
        }

        foreach (['cc', 'bcc'] as $field) {
            if (isset($input[$field]) && (! is_array($input[$field]) || count($input[$field]) > 50)) {
                throw new InvalidArgumentException("ViaPost {$field} must contain at most 50 recipients.");
            }
        }

        if (isset($input['variables']) && (! is_array($input['variables']) || count($input['variables']) > 100)) {
            throw new InvalidArgumentException('ViaPost variables must contain at most 100 properties.');
        }
        if (isset($input['attachments']) && (! is_array($input['attachments']) || count($input['attachments']) > 10)) {
            throw new InvalidArgumentException('ViaPost attachments must contain at most 10 items.');
        }
    }
}

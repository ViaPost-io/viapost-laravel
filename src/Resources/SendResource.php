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

    private function validateIdempotencyKey(string $value): void
    {
        if (preg_match('/\A[\x21-\x7E]{1,255}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('ViaPost idempotency key must contain between 1 and 255 visible ASCII characters.');
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

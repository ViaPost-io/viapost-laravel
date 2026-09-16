<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Responses;

use JsonSerializable;
use Stringable;
use WeakMap;

/**
 * A webhook mutation response whose one-time secret must be requested explicitly.
 *
 * Normal debug, JSON and logging representations intentionally omit the secret.
 */
final class WebhookSecretResponse implements JsonSerializable, Stringable
{
    /** @var WeakMap<object, ?string>|null */
    private static ?WeakMap $secrets = null;

    /** @var array<string, mixed> */
    private array $safeData;

    /** @param array<string, mixed> $response */
    public function __construct(array $response)
    {
        $secret = $response['secret'] ?? null;
        self::secrets()[$this] = is_string($secret) ? $secret : null;
        unset($response['secret']);
        $this->safeData = $response;
    }

    public function secret(): ?string
    {
        return self::secrets()[$this] ?? null;
    }

    /** @return array{safeData: array<string, mixed>} */
    public function __serialize(): array
    {
        return ['safeData' => $this->safeData];
    }

    /** @param array{safeData?: mixed} $data */
    public function __unserialize(array $data): void
    {
        $safeData = $data['safeData'] ?? [];
        $this->safeData = is_array($safeData) ? $safeData : [];
        self::secrets()[$this] = null;
    }

    /** @param array{safeData?: mixed} $properties */
    public static function __set_state(array $properties): self
    {
        $safeData = $properties['safeData'] ?? [];

        return new self(is_array($safeData) ? $safeData : []);
    }

    /** @return array<string, mixed> */
    public function endpoint(): array
    {
        $endpoint = $this->safeData['endpoint'] ?? [];

        return is_array($endpoint) ? $endpoint : [];
    }

    public function rotatedAt(): ?string
    {
        $rotatedAt = $this->safeData['rotated_at'] ?? null;

        return is_string($rotatedAt) ? $rotatedAt : null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->safeData;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->safeData;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->safeData;
    }

    public function __toString(): string
    {
        $encoded = json_encode($this->safeData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '{}';
    }

    /** @return WeakMap<object, ?string> */
    private static function secrets(): WeakMap
    {
        return self::$secrets ??= new WeakMap;
    }
}

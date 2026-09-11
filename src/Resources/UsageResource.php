<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Resources;

final readonly class UsageResource extends Resource
{
    /** @return array<string, mixed> */
    public function retrieve(): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', '/v1/usage');
    }
}

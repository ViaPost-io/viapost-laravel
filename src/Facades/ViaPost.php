<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use ViaPost\Laravel\Client;

/** @mixin Client */
final class ViaPost extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}

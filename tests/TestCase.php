<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use ViaPost\Laravel\ViaPostServiceProvider;

abstract class TestCase extends Orchestra
{
    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [ViaPostServiceProvider::class];
    }
}

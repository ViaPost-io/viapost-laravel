<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ViaPost\Laravel\Client;

final class ReleaseDocumentationTest extends TestCase
{
    public function test_release_facing_documentation_matches_the_sdk_version(): void
    {
        $root = dirname(__DIR__, 2);
        $version = Client::VERSION;
        $minor = preg_replace('/\.\d+$/', '', $version);
        self::assertIsString($minor);
        $publishedMinor = '0.3';

        $readme = file_get_contents($root.'/README.md');
        $security = file_get_contents($root.'/SECURITY.md');
        $changelog = file_get_contents($root.'/CHANGELOG.md');
        self::assertIsString($readme);
        self::assertIsString($security);
        self::assertIsString($changelog);

        self::assertSame(2, substr_count($readme, "composer require viapost/laravel-sdk:^{$publishedMinor}"));
        self::assertStringContainsString("`{$version}` ainda não foi publicada", $readme);
        self::assertStringContainsString("latest published `{$publishedMinor}.x` release", $security);
        self::assertStringContainsString("### Planned for {$version}", $changelog);
    }
}

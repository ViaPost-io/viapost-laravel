<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use ViaPost\Laravel\Client;
use ViaPost\Laravel\Facades\ViaPost;
use ViaPost\Laravel\Tests\TestCase;
use ViaPost\Laravel\ViaPostServiceProvider;

final class LaravelIntegrationTest extends TestCase
{
    public function test_provider_binds_the_configured_client_as_a_singleton(): void
    {
        config()->set('viapost.api_key', 'test-key');
        config()->set('viapost.base_url', 'https://example.test');

        self::assertNotNull($this->app);
        $first = $this->app->make(Client::class);
        $second = $this->app->make(Client::class);

        self::assertSame($first, $second);
    }

    public function test_facade_resolves_the_client_and_uses_laravel_http_fakes(): void
    {
        config()->set('viapost.api_key', 'test-key');
        Http::preventStrayRequests();
        Http::fake(['https://api.viapost.io/v1/usage' => Http::response(['used' => 42])]);

        self::assertSame(['used' => 42], ViaPost::usage()->retrieve());
        Http::assertSentCount(1);
    }

    public function test_config_is_merged_and_publishable(): void
    {
        self::assertSame('https://api.viapost.io', config('viapost.base_url'));

        $paths = ServiceProvider::pathsToPublish(ViaPostServiceProvider::class, 'viapost-config');

        self::assertArrayHasKey(dirname(__DIR__, 2).'/config/viapost.php', $paths);
        self::assertSame(config_path('viapost.php'), $paths[dirname(__DIR__, 2).'/config/viapost.php']);
    }

    public function test_composer_declares_auto_discovery(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertArrayHasKey('extra', $composer);
        self::assertIsArray($composer['extra']);
        self::assertArrayHasKey('laravel', $composer['extra']);
        self::assertIsArray($composer['extra']['laravel']);
        self::assertArrayHasKey('providers', $composer['extra']['laravel']);
        self::assertIsArray($composer['extra']['laravel']['providers']);
        self::assertArrayHasKey('aliases', $composer['extra']['laravel']);
        self::assertIsArray($composer['extra']['laravel']['aliases']);

        self::assertContains(ViaPostServiceProvider::class, $composer['extra']['laravel']['providers']);
        self::assertSame(ViaPost::class, $composer['extra']['laravel']['aliases']['ViaPost']);
    }
}

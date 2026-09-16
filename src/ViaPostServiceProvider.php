<?php

declare(strict_types=1);

namespace ViaPost\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

final class ViaPostServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/viapost.php', 'viapost');

        $this->app->singleton(Client::class, function (Application $app): Client {
            /** @var array<string, mixed> $config */
            $config = $app->make('config')->get('viapost', []);
            /** @var array<string, mixed> $retry */
            $retry = is_array($config['retry'] ?? null) ? $config['retry'] : [];

            return new Client(
                apiKey: self::stringConfig($config, 'api_key'),
                baseUrl: self::stringConfig($config, 'base_url', 'https://api.viapost.io'),
                timeout: self::intConfig($config, 'timeout', 60),
                connectTimeout: self::intConfig($config, 'connect_timeout', 10),
                maxResponseBytes: self::intConfig($config, 'max_response_bytes', 10 * 1024 * 1024),
                maxRetries: self::intConfig($retry, 'max_retries', 2),
                retryBaseDelayMs: self::intConfig($retry, 'base_delay_ms', 250),
                retryMaxDelayMs: self::intConfig($retry, 'max_delay_ms', 30_000),
                maxRawResponseBytes: self::intConfig($config, 'max_raw_response_bytes', 40 * 1024 * 1024),
                http: $app->make(Factory::class),
            );
        });

        $this->app->alias(Client::class, 'viapost');
    }

    public function boot(): void
    {
        $this->publishes([
            dirname(__DIR__).'/config/viapost.php' => config_path('viapost.php'),
        ], 'viapost-config');
    }

    /** @param array<string, mixed> $config */
    private static function stringConfig(array $config, string $key, string $default = ''): string
    {
        $value = $config[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /** @param array<string, mixed> $config */
    private static function intConfig(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }
}

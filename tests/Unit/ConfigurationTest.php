<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ViaPost\Laravel\Client;

final class ConfigurationTest extends TestCase
{
    public function test_it_rejects_an_empty_api_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('api key');

        new Client(apiKey: '   ');
    }

    public function test_it_rejects_control_characters_in_an_api_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('api key');

        new Client(apiKey: "safe-prefix\r\nInjected: value");
    }

    #[DataProvider('nonVisibleAsciiApiKeys')]
    public function test_it_rejects_api_keys_outside_visible_ascii(string $apiKey): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('visible ASCII');

        new Client(apiKey: $apiKey);
    }

    /** @return iterable<string, array{string}> */
    public static function nonVisibleAsciiApiKeys(): iterable
    {
        yield 'space' => ['key with space'];
        yield 'non-ASCII' => ['chave-secréta'];
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeBaseUrls(): iterable
    {
        yield 'relative' => ['api.example.test'];
        yield 'unsupported scheme' => ['ftp://api.example.test'];
        yield 'unencrypted remote host' => ['http://api.example.test'];
        yield 'embedded credentials' => ['https://user:password@api.example.test'];
        yield 'query string' => ['https://api.example.test?tenant=other'];
        yield 'fragment' => ['https://api.example.test#other'];
        yield 'invalid IPv4 lookalike' => ['http://127.999.0.1'];
    }

    #[DataProvider('unsafeBaseUrls')]
    public function test_it_rejects_unsafe_base_urls(string $baseUrl): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Client(apiKey: 'test', baseUrl: $baseUrl);
    }

    #[DataProvider('loopbackBaseUrls')]
    public function test_it_allows_plain_http_only_for_loopback(string $baseUrl): void
    {
        self::assertInstanceOf(Client::class, new Client(apiKey: 'test', baseUrl: $baseUrl));
    }

    /** @return iterable<string, array{string}> */
    public static function loopbackBaseUrls(): iterable
    {
        yield 'localhost' => ['http://localhost:15080'];
        yield 'IPv4 loopback' => ['http://127.0.0.2:15080'];
        yield 'IPv6 loopback' => ['http://[::1]:15080'];
    }

    public function test_it_rejects_non_positive_limits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Client(apiKey: 'test', timeout: 0);
    }

    public function test_it_rejects_a_non_positive_raw_response_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Client(apiKey: 'test', maxRawResponseBytes: 0);
    }

    public function test_it_rejects_an_unbounded_raw_response_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Client(apiKey: 'test', maxRawResponseBytes: (128 * 1024 * 1024) + 1);
    }

    public function test_it_rejects_an_unbounded_json_response_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Client(apiKey: 'test', maxResponseBytes: (64 * 1024 * 1024) + 1);
    }

    public function test_debug_output_never_contains_the_api_key(): void
    {
        $client = new Client(apiKey: 'synthetic-secret-api-key');

        ob_start();
        var_dump($client);
        $debug = ob_get_clean();

        self::assertIsString($debug);
        self::assertStringNotContainsString('synthetic-secret-api-key', $debug);
        self::assertStringContainsString('[REDACTED]', $debug);
    }
}

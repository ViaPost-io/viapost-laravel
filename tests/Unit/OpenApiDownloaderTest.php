<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Tests\Unit;

use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ViaPost\Laravel\Support\OpenApiDownloader;

final class OpenApiDownloaderTest extends TestCase
{
    #[DataProvider('unsafeSources')]
    public function test_it_rejects_unsafe_contract_sources(string $source): void
    {
        $this->expectException(InvalidArgumentException::class);

        OpenApiDownloader::validateUri(new Uri($source));
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeSources(): iterable
    {
        yield 'http' => ['http://docs.viapost.io/openapi/public.yaml'];
        yield 'credentials' => ['https://user:pass@docs.viapost.io/openapi/public.yaml'];
        yield 'fragment' => ['https://docs.viapost.io/openapi/public.yaml#secret'];
        yield 'relative' => ['/openapi/public.yaml'];
        yield 'abbreviated IPv4 loopback' => ['https://127.1/openapi/public.yaml'];
        yield 'integer IPv4 loopback' => ['https://2130706433/openapi/public.yaml'];
        yield 'hexadecimal IPv4 loopback' => ['https://0x7f000001/openapi/public.yaml'];
    }

    public function test_it_allows_only_same_origin_https_redirects(): void
    {
        $origin = OpenApiDownloader::origin(new Uri('https://docs.viapost.io/openapi/public.yaml'));

        OpenApiDownloader::validateRedirect(new Uri('https://docs.viapost.io/openapi/current.yaml'), $origin);
        $this->addToAssertionCount(1);

        $this->expectException(RuntimeException::class);
        OpenApiDownloader::validateRedirect(new Uri('https://attacker.example/openapi.yaml'), $origin);
    }
}

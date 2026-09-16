<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

final class OpenApiDownloader
{
    public const MAX_BYTES = 2_097_152;

    public const MAX_REDIRECTS = 3;

    public static function download(string $source): string
    {
        $origin = self::origin(self::validateUri(new Uri($source)));
        $downloadedBytes = 0;

        $client = new Client([
            'allow_redirects' => [
                'max' => self::MAX_REDIRECTS,
                'strict' => true,
                'referer' => false,
                'on_redirect' => static function (
                    RequestInterface $request,
                    ResponseInterface $response,
                    UriInterface $uri,
                ) use ($origin): void {
                    unset($request, $response);
                    self::validateRedirect($uri, $origin);
                },
            ],
            'connect_timeout' => 10,
            'decode_content' => false,
            'headers' => ['Accept-Encoding' => 'identity'],
            'http_errors' => true,
            'timeout' => 60,
        ]);

        $response = $client->request('GET', $source, [
            'on_headers' => static function (ResponseInterface $response): void {
                $length = $response->getHeaderLine('Content-Length');
                if (preg_match('/\A[0-9]+\z/D', $length) === 1 && self::decimalExceeds($length, self::MAX_BYTES)) {
                    throw new RuntimeException('OpenAPI contract exceeds the 2 MiB limit.');
                }
            },
            'progress' => static function (
                int $downloadTotal,
                int $downloaded,
                int $uploadTotal,
                int $uploaded,
            ) use (&$downloadedBytes): void {
                unset($uploadTotal, $uploaded);
                $downloadedBytes = max($downloadedBytes, $downloaded);
                if ($downloadTotal > self::MAX_BYTES || $downloadedBytes > self::MAX_BYTES) {
                    throw new RuntimeException('OpenAPI contract exceeds the 2 MiB limit.');
                }
            },
            'stream' => true,
        ]);

        $body = $response->getBody();
        $result = '';
        while (! $body->eof()) {
            $result .= $body->read(16_384);
            if (strlen($result) > self::MAX_BYTES) {
                throw new RuntimeException('OpenAPI contract exceeds the 2 MiB limit.');
            }
        }

        return $result;
    }

    public static function validateRedirect(UriInterface $uri, string $expectedOrigin): void
    {
        $validated = self::validateUri($uri);
        if (self::origin($validated) !== $expectedOrigin) {
            throw new RuntimeException('OpenAPI redirects must remain on the original origin.');
        }
    }

    public static function validateUri(UriInterface $uri): UriInterface
    {
        $host = strtolower(trim($uri->getHost(), '[]'));
        if (strtolower($uri->getScheme()) !== 'https'
            || $host === ''
            || $uri->getUserInfo() !== ''
            || $uri->getFragment() !== ''
            || self::isIpLiteralOrNumericAlias($host)) {
            throw new InvalidArgumentException('OpenAPI source must be an absolute HTTPS URL without credentials or fragment.');
        }

        return $uri;
    }

    public static function origin(UriInterface $uri): string
    {
        $validated = self::validateUri($uri);
        $port = $validated->getPort() ?? 443;

        return 'https://'.strtolower($validated->getHost()).':'.$port;
    }

    private static function decimalExceeds(string $decimal, int $limit): bool
    {
        $decimal = ltrim($decimal, '0');
        if ($decimal === '') {
            return false;
        }
        $maximum = (string) $limit;

        return strlen($decimal) > strlen($maximum)
            || (strlen($decimal) === strlen($maximum) && strcmp($decimal, $maximum) > 0);
    }

    private static function isIpLiteralOrNumericAlias(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return preg_match('/\A(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+)){0,3}\z/iD', $host) === 1;
    }
}

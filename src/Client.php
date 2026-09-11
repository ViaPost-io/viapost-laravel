<?php

declare(strict_types=1);

namespace ViaPost\Laravel;

use Illuminate\Http\Client\ConnectionException as LaravelConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as LaravelRequest;
use Illuminate\Http\Client\RequestException as LaravelRequestException;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use ViaPost\Laravel\Exceptions\ApiException;
use ViaPost\Laravel\Exceptions\ConnectionException;
use ViaPost\Laravel\Exceptions\ResponseTooLargeException;
use ViaPost\Laravel\Exceptions\TimeoutException;
use ViaPost\Laravel\Exceptions\UnexpectedResponseException;
use ViaPost\Laravel\Resources\AutomationsResource;
use ViaPost\Laravel\Resources\DomainsResource;
use ViaPost\Laravel\Resources\MessagesResource;
use ViaPost\Laravel\Resources\SendResource;
use ViaPost\Laravel\Resources\TemplatesResource;
use ViaPost\Laravel\Resources\UsageResource;
use ViaPost\Laravel\Resources\WebhooksResource;

final class Client
{
    public const VERSION = '0.1.1';

    /** @var list<string> */
    private const PROTECTED_REQUEST_HEADERS = [
        'accept',
        'authorization',
        'connection',
        'content-length',
        'content-type',
        'cookie',
        'host',
        'keep-alive',
        'proxy-authenticate',
        'proxy-authorization',
        'proxy-connection',
        'set-cookie',
        'te',
        'trailer',
        'transfer-encoding',
        'upgrade',
        'user-agent',
        'x-csrf-token',
        'x-viapost-csrf',
        'x-xsrf-token',
    ];

    private readonly string $apiKey;

    private readonly string $baseUrl;

    private readonly Factory $http;

    private readonly SendResource $sendResource;

    private readonly MessagesResource $messagesResource;

    private readonly DomainsResource $domainsResource;

    private readonly TemplatesResource $templatesResource;

    private readonly WebhooksResource $webhooksResource;

    private readonly AutomationsResource $automationsResource;

    private readonly UsageResource $usageResource;

    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://api.viapost.io',
        private readonly int $timeout = 60,
        private readonly int $connectTimeout = 10,
        private readonly int $maxResponseBytes = 10_485_760,
        private readonly int $maxRetries = 2,
        private readonly int $retryBaseDelayMs = 250,
        private readonly int $retryMaxDelayMs = 30_000,
        ?Factory $http = null,
    ) {
        $apiKey = trim($apiKey);
        if (preg_match('/\A[\x21-\x7E]+\z/D', $apiKey) !== 1) {
            throw new InvalidArgumentException('ViaPost api key must contain visible ASCII characters only.');
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = self::normalizeBaseUrl($baseUrl);
        $this->assertPositive($timeout, 'timeout');
        $this->assertPositive($connectTimeout, 'connect timeout');
        $this->assertPositive($maxResponseBytes, 'maximum response bytes');
        $this->assertNonNegative($maxRetries, 'maximum retries');
        $this->assertNonNegative($retryBaseDelayMs, 'retry base delay');
        $this->assertNonNegative($retryMaxDelayMs, 'retry maximum delay');
        $this->http = $http ?? new Factory;
        $this->sendResource = new SendResource($this);
        $this->messagesResource = new MessagesResource($this);
        $this->domainsResource = new DomainsResource($this);
        $this->templatesResource = new TemplatesResource($this);
        $this->webhooksResource = new WebhooksResource($this);
        $this->automationsResource = new AutomationsResource($this);
        $this->usageResource = new UsageResource($this);
    }

    public function send(): SendResource
    {
        return $this->sendResource;
    }

    public function messages(): MessagesResource
    {
        return $this->messagesResource;
    }

    public function domains(): DomainsResource
    {
        return $this->domainsResource;
    }

    public function templates(): TemplatesResource
    {
        return $this->templatesResource;
    }

    public function webhooks(): WebhooksResource
    {
        return $this->webhooksResource;
    }

    public function automations(): AutomationsResource
    {
        return $this->automationsResource;
    }

    public function usage(): UsageResource
    {
        return $this->usageResource;
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed>|list<mixed>|scalar|null
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        array $headers = [],
    ): array|string|int|float|bool|null {
        $method = strtoupper($method);
        $url = $this->baseUrl.'/'.ltrim($path, '/');
        $headers = $this->sanitizeRequestHeaders($headers);
        $safeToRetry = in_array($method, ['GET', 'HEAD'], true);
        $attempt = 0;

        do {
            $responseTooLarge = false;

            try {
                $pending = $this->http
                    ->withHeaders($headers)
                    ->withToken($this->apiKey)
                    ->acceptJson()
                    ->withUserAgent('viapost-laravel/'.self::VERSION)
                    ->timeout($this->timeout)
                    ->connectTimeout($this->connectTimeout)
                    ->withoutRedirecting()
                    ->beforeSending(fn (LaravelRequest $request): RequestInterface => $this->enforceRequestBoundary(
                        $request->toPsrRequest(),
                    ));

                /** @var array<string, mixed> $options */
                $options = [
                    'cookies' => false,
                    'on_headers' => function (ResponseInterface $response) use (&$responseTooLarge): void {
                        if ($this->advertisedBodyExceedsLimit(
                            $response->getHeaderLine('Content-Length'),
                            $response->getHeaderLine('Content-Encoding'),
                        )) {
                            $responseTooLarge = true;

                            throw new ResponseTooLargeException($this->maxResponseBytes);
                        }
                    },
                    'progress' => function (int $downloadTotal, int $downloadedBytes, int $uploadTotal, int $uploadedBytes) use (&$responseTooLarge): void {
                        unset($uploadTotal, $uploadedBytes);

                        if ($downloadTotal > $this->maxResponseBytes || $downloadedBytes > $this->maxResponseBytes) {
                            $responseTooLarge = true;

                            throw new ResponseTooLargeException($this->maxResponseBytes);
                        }
                    },
                ];
                if ($query !== []) {
                    $options['query'] = $query;
                }
                if ($body !== null) {
                    $options['json'] = $body;
                }

                $response = $pending->send($method, $url, $options);
            } catch (Throwable $exception) {
                if ($responseTooLarge) {
                    throw new ResponseTooLargeException($this->maxResponseBytes);
                }

                if ($exception instanceof LaravelRequestException || ! $exception instanceof LaravelConnectionException) {
                    throw $exception;
                }

                if ($this->isTimeout($exception)) {
                    throw new TimeoutException($this->timeout);
                }

                throw new ConnectionException(
                    "Unable to connect to ViaPost while requesting {$method} {$url}.",
                );
            }

            if (! ($safeToRetry && $this->isRetryable($response) && $attempt < $this->maxRetries)) {
                return $this->decode($response, $method, $url);
            }

            $this->waitBeforeRetry($response, $attempt);
            $attempt++;
        } while (true);
    }

    /** @return array<string, mixed>|list<mixed>|scalar|null */
    private function decode(Response $response, string $method, string $url): array|string|int|float|bool|null
    {
        if ($this->advertisedBodyExceedsLimit(
            $response->header('Content-Length'),
            $response->header('Content-Encoding'),
        )) {
            throw new ResponseTooLargeException($this->maxResponseBytes);
        }

        $rawBody = $response->body();
        if (strlen($rawBody) > $this->maxResponseBytes) {
            throw new ResponseTooLargeException($this->maxResponseBytes);
        }

        $body = null;
        if ($rawBody !== '') {
            try {
                /** @var array<string, mixed>|list<mixed>|scalar|null $body */
                $body = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                if ($response->successful()) {
                    throw new UnexpectedResponseException('ViaPost returned invalid JSON.', previous: $exception);
                }
                $body = $rawBody;
            }
        }

        if (! $response->successful()) {
            $requestId = $this->nonEmpty($response->header('X-Request-Id'))
                ?? $this->nonEmpty($response->header('X-Correlation-Id'))
                ?? $this->requestIdFromBody($body);

            throw new ApiException(
                $this->errorMessage($body, $response->status()),
                $response->status(),
                $method,
                $url,
                $body,
                $requestId,
                $response->headers(),
            );
        }

        return $body;
    }

    private function isRetryable(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function sanitizeRequestHeaders(array $headers): array
    {
        $safeHeaders = [];
        $hasIdempotencyKey = false;

        foreach ($headers as $name => $value) {
            $normalizedName = strtolower($name);
            if (in_array($normalizedName, self::PROTECTED_REQUEST_HEADERS, true)) {
                continue;
            }

            if ($normalizedName === 'idempotency-key') {
                if ($hasIdempotencyKey) {
                    throw new InvalidArgumentException('ViaPost request headers must contain at most one idempotency key.');
                }
                $this->assertVisibleAsciiIdempotencyKey($value);
                $safeHeaders['Idempotency-Key'] = $value;
                $hasIdempotencyKey = true;

                continue;
            }

            $safeHeaders[$name] = $value;
        }

        return $safeHeaders;
    }

    private function enforceRequestBoundary(RequestInterface $request): RequestInterface
    {
        foreach (self::PROTECTED_REQUEST_HEADERS as $header) {
            $request = $request->withoutHeader($header);
        }

        $request = $request
            ->withHeader('Authorization', 'Bearer '.$this->apiKey)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', 'viapost-laravel/'.self::VERSION)
            ->withHeader('Host', $request->getUri()->getAuthority());

        $bodySize = $request->getBody()->getSize();
        if ($bodySize !== null && $bodySize > 0) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Content-Length', (string) $bodySize);
        }

        return $request;
    }

    private function assertVisibleAsciiIdempotencyKey(string $value): void
    {
        if (preg_match('/\A[\x21-\x7E]{1,255}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('ViaPost idempotency key must contain between 1 and 255 visible ASCII characters.');
        }
    }

    private function advertisedBodyExceedsLimit(string $contentLength, string $contentEncoding): bool
    {
        foreach (explode(',', strtolower($contentEncoding)) as $encoding) {
            $encoding = trim($encoding);
            if ($encoding !== '' && $encoding !== 'identity') {
                return false;
            }
        }

        if (preg_match('/\A[0-9]+\z/D', $contentLength) !== 1) {
            return false;
        }

        return $this->decimalExceedsLimit($contentLength, $this->maxResponseBytes);
    }

    private function decimalExceedsLimit(string $decimal, int $limit): bool
    {
        $decimal = ltrim($decimal, '0');
        if ($decimal === '') {
            return false;
        }

        $limit = (string) $limit;

        return strlen($decimal) > strlen($limit)
            || (strlen($decimal) === strlen($limit) && strcmp($decimal, $limit) > 0);
    }

    private function isTimeout(Throwable $exception): bool
    {
        $current = $exception;
        do {
            $message = strtolower($current->getMessage());
            if (str_contains($message, 'timed out')
                || str_contains($message, 'timeout')
                || str_contains($message, 'curl error 28')) {
                return true;
            }
            $current = $current->getPrevious();
        } while ($current !== null);

        return false;
    }

    private function waitBeforeRetry(Response $response, int $attempt): void
    {
        $milliseconds = $this->retryAfterMilliseconds($response->header('Retry-After'))
            ?? $this->exponentialRetryDelayMilliseconds($attempt);

        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    private function retryAfterMilliseconds(string $retryAfter): ?int
    {
        $retryAfter = trim($retryAfter);
        if ($retryAfter === '') {
            return null;
        }

        if (ctype_digit($retryAfter)) {
            if ($this->decimalExceedsLimit($retryAfter, intdiv($this->retryMaxDelayMs, 1000))) {
                return $this->retryMaxDelayMs;
            }

            return min((int) $retryAfter * 1000, $this->retryMaxDelayMs);
        }

        $retryAt = strtotime($retryAfter);
        if ($retryAt === false) {
            return null;
        }

        $seconds = max(0, $retryAt - time());
        if ($seconds > intdiv($this->retryMaxDelayMs, 1000)) {
            return $this->retryMaxDelayMs;
        }

        return min($seconds * 1000, $this->retryMaxDelayMs);
    }

    private function exponentialRetryDelayMilliseconds(int $attempt): int
    {
        if ($this->retryBaseDelayMs === 0 || $this->retryMaxDelayMs === 0) {
            return 0;
        }

        $factor = 2 ** min($attempt, 30);
        if ($this->retryBaseDelayMs > intdiv($this->retryMaxDelayMs, $factor)) {
            return $this->retryMaxDelayMs;
        }

        return min($this->retryBaseDelayMs * $factor, $this->retryMaxDelayMs);
    }

    private function nonEmpty(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function requestIdFromBody(mixed $body): ?string
    {
        if (! is_array($body)) {
            return null;
        }

        $requestId = $body['request_id'] ?? null;
        if (isset($body['error']) && is_array($body['error'])) {
            $requestId = $body['error']['request_id'] ?? $requestId;
        }

        return is_string($requestId) ? $this->nonEmpty($requestId) : null;
    }

    private function errorMessage(mixed $body, int $status): string
    {
        if (is_array($body)) {
            $error = $body['error'] ?? null;
            if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
                return $error['message'];
            }
            if (is_string($error) && $error !== '') {
                return $error;
            }
            if (isset($body['message']) && is_string($body['message'])) {
                return $body['message'];
            }
        }

        return "ViaPost API request failed with status {$status}.";
    }

    private static function normalizeBaseUrl(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('ViaPost base URL must be absolute.');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('ViaPost base URL must use HTTP or HTTPS.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('ViaPost base URL must not contain credentials.');
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('ViaPost base URL must not contain a query string or fragment.');
        }

        if ($scheme === 'http' && ! self::isLoopback($parts['host'])) {
            throw new InvalidArgumentException('ViaPost base URL must use HTTPS unless it targets loopback.');
        }

        return rtrim($baseUrl, '/');
    }

    private static function isLoopback(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === '::1') {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && str_starts_with($host, '127.');
    }

    private function assertPositive(int $value, string $name): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException("ViaPost {$name} must be positive.");
        }
    }

    private function assertNonNegative(int $value, string $name): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException("ViaPost {$name} must not be negative.");
        }
    }
}

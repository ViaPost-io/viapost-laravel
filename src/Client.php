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
use LogicException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use ViaPost\Laravel\Exceptions\ApiException;
use ViaPost\Laravel\Exceptions\ConnectionException;
use ViaPost\Laravel\Exceptions\ResponseTooLargeException;
use ViaPost\Laravel\Exceptions\TimeoutException;
use ViaPost\Laravel\Exceptions\UnexpectedResponseException;
use ViaPost\Laravel\Resources\AutomationsResource;
use ViaPost\Laravel\Resources\ContactsResource;
use ViaPost\Laravel\Resources\DomainsResource;
use ViaPost\Laravel\Resources\InboundMessagesResource;
use ViaPost\Laravel\Resources\MessagesResource;
use ViaPost\Laravel\Resources\SegmentsResource;
use ViaPost\Laravel\Resources\SendResource;
use ViaPost\Laravel\Resources\SuppressionsResource;
use ViaPost\Laravel\Resources\TemplatesResource;
use ViaPost\Laravel\Resources\UsageResource;
use ViaPost\Laravel\Resources\WebhooksResource;
use WeakMap;

final class Client
{
    public const VERSION = '0.4.0';

    private const MAX_JSON_RESPONSE_BYTES = 67_108_864;

    private const MAX_RAW_RESPONSE_BYTES = 134_217_728;

    /** @var list<string> */
    private const PROTECTED_REQUEST_HEADERS = [
        'accept',
        'accept-encoding',
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

    /** @var WeakMap<object, string>|null */
    private static ?WeakMap $apiKeys = null;

    private readonly string $baseUrl;

    private readonly Factory $http;

    private readonly SendResource $sendResource;

    private readonly ContactsResource $contactsResource;

    private readonly MessagesResource $messagesResource;

    private readonly InboundMessagesResource $inboundMessagesResource;

    private readonly SuppressionsResource $suppressionsResource;

    private readonly DomainsResource $domainsResource;

    private readonly SegmentsResource $segmentsResource;

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
        private readonly int $maxRawResponseBytes = 41_943_040,
    ) {
        $apiKey = trim($apiKey);
        if (preg_match('/\A[\x21-\x7E]+\z/D', $apiKey) !== 1) {
            throw new InvalidArgumentException('ViaPost api key must contain visible ASCII characters only.');
        }

        self::apiKeys()[$this] = $apiKey;
        $this->baseUrl = self::normalizeBaseUrl($baseUrl);
        $this->assertPositive($timeout, 'timeout');
        $this->assertPositive($connectTimeout, 'connect timeout');
        $this->assertPositive($maxResponseBytes, 'maximum response bytes');
        if ($maxResponseBytes > self::MAX_JSON_RESPONSE_BYTES) {
            throw new InvalidArgumentException('ViaPost maximum JSON response bytes cannot exceed 64 MiB.');
        }
        $this->assertPositive($maxRawResponseBytes, 'maximum raw response bytes');
        if ($maxRawResponseBytes > self::MAX_RAW_RESPONSE_BYTES) {
            throw new InvalidArgumentException('ViaPost maximum raw response bytes cannot exceed 128 MiB.');
        }
        $this->assertNonNegative($maxRetries, 'maximum retries');
        $this->assertNonNegative($retryBaseDelayMs, 'retry base delay');
        $this->assertNonNegative($retryMaxDelayMs, 'retry maximum delay');
        $this->http = $http ?? new Factory;
        $this->sendResource = new SendResource($this);
        $this->contactsResource = new ContactsResource($this);
        $this->messagesResource = new MessagesResource($this);
        $this->inboundMessagesResource = new InboundMessagesResource($this);
        $this->suppressionsResource = new SuppressionsResource($this);
        $this->domainsResource = new DomainsResource($this);
        $this->segmentsResource = new SegmentsResource($this);
        $this->templatesResource = new TemplatesResource($this);
        $this->webhooksResource = new WebhooksResource($this);
        $this->automationsResource = new AutomationsResource($this);
        $this->usageResource = new UsageResource($this);
    }

    /** @return array<string, int|string> */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => '[REDACTED]',
            'baseUrl' => $this->baseUrl,
            'timeout' => $this->timeout,
            'connectTimeout' => $this->connectTimeout,
            'maxResponseBytes' => $this->maxResponseBytes,
            'maxRawResponseBytes' => $this->maxRawResponseBytes,
            'maxRetries' => $this->maxRetries,
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('ViaPost Client must not be serialized because it contains credentials.');
    }

    public function send(): SendResource
    {
        return $this->sendResource;
    }

    public function contacts(): ContactsResource
    {
        return $this->contactsResource;
    }

    public function messages(): MessagesResource
    {
        return $this->messagesResource;
    }

    public function inboundMessages(): InboundMessagesResource
    {
        return $this->inboundMessagesResource;
    }

    public function suppressions(): SuppressionsResource
    {
        return $this->suppressionsResource;
    }

    public function domains(): DomainsResource
    {
        return $this->domainsResource;
    }

    public function segments(): SegmentsResource
    {
        return $this->segmentsResource;
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
        $response = $this->performRequest($method, $path, $query, jsonBody: $body, headers: $headers);

        return $this->decode($response, strtoupper($method), $this->url($path));
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @param  array<string, string>  $headers
     * @return array<string, mixed>|list<mixed>|scalar|null
     */
    public function requestJsonWithRawBody(
        string $method,
        string $path,
        string $body,
        string $contentType,
        array $query = [],
        array $headers = [],
    ): array|string|int|float|bool|null {
        $response = $this->performRequest(
            $method,
            $path,
            $query,
            rawBody: $body,
            headers: $headers,
            contentType: $contentType,
        );

        return $this->decode($response, strtoupper($method), $this->url($path));
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @param  array<string, string>  $headers
     */
    public function requestRaw(
        string $method,
        string $path,
        array $query = [],
        string $accept = 'application/octet-stream',
        array $headers = [],
    ): string {
        $response = $this->performRequest(
            $method,
            $path,
            $query,
            headers: $headers,
            accept: $accept,
            successfulResponseLimit: $this->maxRawResponseBytes,
        );

        if (! $response->successful()) {
            $this->decode($response, strtoupper($method), $this->url($path));
        }

        $body = $response->body();
        if (strlen($body) > $this->maxRawResponseBytes) {
            throw new ResponseTooLargeException($this->maxRawResponseBytes);
        }

        return $body;
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @param  array<string, mixed>|null  $jsonBody
     * @param  array<string, string>  $headers
     */
    private function performRequest(
        string $method,
        string $path,
        array $query = [],
        ?array $jsonBody = null,
        ?string $rawBody = null,
        array $headers = [],
        string $accept = 'application/json',
        ?string $contentType = null,
        ?int $successfulResponseLimit = null,
    ): Response {
        if ($jsonBody !== null && $rawBody !== null) {
            throw new InvalidArgumentException('ViaPost requests cannot contain both JSON and raw bodies.');
        }

        $method = strtoupper($method);
        $url = $this->url($path);
        $headers = $this->sanitizeRequestHeaders($headers);
        $safeToRetry = in_array($method, ['GET', 'HEAD'], true);
        $successfulResponseLimit ??= $this->maxResponseBytes;
        $attempt = 0;

        do {
            $responseTooLargeLimit = null;
            $activeResponseLimit = $this->maxResponseBytes;

            try {
                $pending = $this->http
                    ->withHeaders($headers)
                    ->withToken($this->apiKey())
                    ->accept($accept)
                    ->withUserAgent('viapost-laravel/'.self::VERSION)
                    ->timeout($this->timeout)
                    ->connectTimeout($this->connectTimeout)
                    ->withoutRedirecting()
                    ->beforeSending(fn (LaravelRequest $request): RequestInterface => $this->enforceRequestBoundary(
                        $request->toPsrRequest(),
                        $accept,
                        $contentType ?? ($jsonBody !== null ? 'application/json' : null),
                    ));

                /** @var array<string, mixed> $options */
                $options = [
                    'cookies' => false,
                    'decode_content' => false,
                    'on_headers' => function (ResponseInterface $response) use (
                        &$activeResponseLimit,
                        &$responseTooLargeLimit,
                        $successfulResponseLimit,
                    ): void {
                        $activeResponseLimit = $response->getStatusCode() >= 200 && $response->getStatusCode() < 300
                            ? $successfulResponseLimit
                            : $this->maxResponseBytes;
                        if ($this->advertisedBodyExceedsLimit(
                            $response->getHeaderLine('Content-Length'),
                            $response->getHeaderLine('Content-Encoding'),
                            $activeResponseLimit,
                        )) {
                            $responseTooLargeLimit = $activeResponseLimit;

                            throw new ResponseTooLargeException($activeResponseLimit);
                        }
                    },
                    'progress' => function (int $downloadTotal, int $downloadedBytes, int $uploadTotal, int $uploadedBytes) use (
                        &$activeResponseLimit,
                        &$responseTooLargeLimit,
                    ): void {
                        unset($uploadTotal, $uploadedBytes);

                        if ($downloadTotal > $activeResponseLimit || $downloadedBytes > $activeResponseLimit) {
                            $responseTooLargeLimit = $activeResponseLimit;

                            throw new ResponseTooLargeException($activeResponseLimit);
                        }
                    },
                ];
                if ($query !== []) {
                    $options['query'] = $query;
                }
                if ($jsonBody !== null) {
                    $options['json'] = $jsonBody;
                } elseif ($rawBody !== null) {
                    $options['body'] = $rawBody;
                }

                $response = $pending->send($method, $url, $options);
            } catch (Throwable $exception) {
                if ($responseTooLargeLimit !== null) {
                    throw new ResponseTooLargeException($responseTooLargeLimit);
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
                return $response;
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
            $this->maxResponseBytes,
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
            $sensitiveValues = [$this->apiKey()];
            $this->collectSensitiveValues($body, $sensitiveValues);
            $this->collectSensitiveHeaderValues($response->headers(), $sensitiveValues);
            $safeBody = $this->redactSensitiveValue($body, $sensitiveValues);
            $safeHeaders = $this->redactSensitiveHeaders($response->headers(), $sensitiveValues);
            $requestId = $this->nonEmpty($response->header('X-Request-Id'))
                ?? $this->nonEmpty($response->header('X-Correlation-Id'))
                ?? $this->requestIdFromBody($body);
            if ($requestId !== null) {
                $requestId = $this->redactSensitiveString($requestId, $sensitiveValues);
            }

            throw new ApiException(
                $this->errorMessage($safeBody, $response->status()),
                $response->status(),
                $method,
                $url,
                $safeBody,
                $requestId,
                $safeHeaders,
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

    private function enforceRequestBoundary(RequestInterface $request, string $accept, ?string $contentType): RequestInterface
    {
        foreach (self::PROTECTED_REQUEST_HEADERS as $header) {
            $request = $request->withoutHeader($header);
        }

        $request = $request
            ->withHeader('Authorization', 'Bearer '.$this->apiKey())
            ->withHeader('Accept', $accept)
            ->withHeader('Accept-Encoding', 'identity')
            ->withHeader('User-Agent', 'viapost-laravel/'.self::VERSION)
            ->withHeader('Host', $request->getUri()->getAuthority());

        $bodySize = $request->getBody()->getSize();
        if ($bodySize !== null && $bodySize > 0) {
            $request = $request
                ->withHeader('Content-Type', $contentType ?? 'application/octet-stream')
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

    private function advertisedBodyExceedsLimit(string $contentLength, string $contentEncoding, int $limit): bool
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

        return $this->decimalExceedsLimit($contentLength, $limit);
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

    /** @param list<string> $sensitiveValues */
    private function collectSensitiveValues(mixed $value, array &$sensitiveValues, ?string $key = null): void
    {
        if (is_array($value)) {
            foreach ($value as $childKey => $childValue) {
                $this->collectSensitiveValues($childValue, $sensitiveValues, is_string($childKey) ? $childKey : null);
            }

            return;
        }

        if ($key !== null && $this->isSensitiveName($key) && is_string($value) && $value !== '') {
            $sensitiveValues[] = $value;
        }
    }

    /**
     * @param  array<string, list<string>>  $headers
     * @param  list<string>  $sensitiveValues
     */
    private function collectSensitiveHeaderValues(array $headers, array &$sensitiveValues): void
    {
        foreach ($headers as $name => $values) {
            if (! $this->isSensitiveName($name)) {
                continue;
            }
            foreach ($values as $value) {
                if ($value !== '') {
                    $sensitiveValues[] = $value;
                }
            }
        }
    }

    /** @param list<string> $sensitiveValues */
    private function redactSensitiveValue(mixed $value, array $sensitiveValues, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveName($key)) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $safe = [];
            foreach ($value as $childKey => $childValue) {
                $safe[$childKey] = $this->redactSensitiveValue(
                    $childValue,
                    $sensitiveValues,
                    is_string($childKey) ? $childKey : null,
                );
            }

            return $safe;
        }

        if (! is_string($value)) {
            return $value;
        }

        return $this->redactSensitiveString($value, $sensitiveValues);
    }

    /**
     * @param  array<string, list<string>>  $headers
     * @param  list<string>  $sensitiveValues
     * @return array<string, list<string>>
     */
    private function redactSensitiveHeaders(array $headers, array $sensitiveValues): array
    {
        $safe = [];
        foreach ($headers as $name => $values) {
            if ($this->isSensitiveName($name)) {
                $safe[$name] = ['[REDACTED]'];

                continue;
            }
            $safe[$name] = array_map(
                fn (string $value): string => $this->redactSensitiveString($value, $sensitiveValues),
                $values,
            );
        }

        return $safe;
    }

    private function isSensitiveName(string $name): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $name));

        foreach (['apikey', 'secret', 'token', 'authorization', 'password', 'cookie'] as $sensitiveName) {
            if (str_contains($normalized, $sensitiveName)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $sensitiveValues */
    private function redactSensitiveString(string $value, array $sensitiveValues): string
    {
        $sensitiveValues = array_values(array_unique(array_filter(
            $sensitiveValues,
            static fn (string $sensitiveValue): bool => $sensitiveValue !== '',
        )));
        if ($sensitiveValues !== []) {
            $value = str_replace($sensitiveValues, '[REDACTED]', $value);
        }

        return (string) preg_replace(
            '/((?:api[_-]?key|secret|token|authorization|password)\s*[:=]\s*)([^\s,;]+)/i',
            '$1[REDACTED]',
            $value,
        );
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

    private function url(string $path): string
    {
        return $this->baseUrl.'/'.ltrim($path, '/');
    }

    private function apiKey(): string
    {
        $apiKey = self::apiKeys()[$this] ?? null;
        if (! is_string($apiKey)) {
            throw new LogicException('ViaPost Client credentials are unavailable.');
        }

        return $apiKey;
    }

    /** @return WeakMap<object, string> */
    private static function apiKeys(): WeakMap
    {
        return self::$apiKeys ??= new WeakMap;
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

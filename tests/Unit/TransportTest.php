<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Tests\Unit;

use Closure;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException as LaravelConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ViaPost\Laravel\Client;
use ViaPost\Laravel\Exceptions\ApiException;
use ViaPost\Laravel\Exceptions\ConnectionException;
use ViaPost\Laravel\Exceptions\ResponseTooLargeException;
use ViaPost\Laravel\Exceptions\TimeoutException;
use ViaPost\Laravel\Responses\WebhookSecretResponse;

final class TransportTest extends TestCase
{
    private Factory $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new Factory;
        $this->http->preventStrayRequests();
    }

    public function test_it_sends_authentication_headers_timeouts_and_query_parameters(): void
    {
        $this->http->fake(['*' => Factory::response(['messages' => []])]);
        $client = new Client('secret', 'https://example.test/api/', timeout: 15, connectTimeout: 3, http: $this->http);

        self::assertSame(['messages' => []], $client->messages()->list(['status' => 'delivered', 'limit' => 25]));

        $this->http->assertSent(function (Request $request): bool {
            self::assertSame('https://example.test/api/v1/messages?status=delivered&limit=25', $request->url());
            self::assertTrue($request->hasHeader('Authorization', 'Bearer secret'));
            self::assertTrue($request->hasHeader('Accept', 'application/json'));
            self::assertTrue($request->hasHeader('User-Agent', 'viapost-laravel/0.2.1'));

            return true;
        });
    }

    public function test_it_returns_null_for_a_successful_empty_response(): void
    {
        $this->http->fake(['*' => Factory::response(null, 204)]);

        (new Client('test', http: $this->http))->domains()->delete('domain-id');

        $this->http->assertSentCount(1);
    }

    public function test_it_rejects_an_oversized_response(): void
    {
        $this->http->fake(['*' => Factory::response(['value' => 'too-large'])]);

        $this->expectException(ResponseTooLargeException::class);
        $this->expectExceptionMessage('8 bytes');

        (new Client('test', maxResponseBytes: 8, http: $this->http))->usage()->retrieve();
    }

    public function test_it_rejects_an_advertised_oversized_response_before_decoding(): void
    {
        $handler = new MockHandler([new PsrResponse(200, ['Content-Length' => '99'], '{}')]);
        $http = $this->factoryUsing($handler);

        try {
            (new Client('test', maxResponseBytes: 8, http: $http))->usage()->retrieve();
            self::fail('Expected the transfer to be rejected from its headers.');
        } catch (ResponseTooLargeException) {
            $options = $handler->getLastOptions();
            self::assertArrayHasKey('on_headers', $options);
            self::assertIsCallable($options['on_headers']);
        }
    }

    public function test_it_aborts_a_chunked_transfer_as_soon_as_the_download_limit_is_crossed(): void
    {
        $downloadedBytes = 0;
        $abortedDuringProgress = false;
        $handler = static function (RequestInterface $request, array $options) use (&$downloadedBytes, &$abortedDuringProgress) {
            self::assertArrayHasKey('progress', $options);
            self::assertIsCallable($options['progress']);

            foreach ([4, 9] as $downloadedBytes) {
                try {
                    $options['progress'](0, $downloadedBytes, 0, 0);
                } catch (ResponseTooLargeException $exception) {
                    $abortedDuringProgress = true;

                    return Create::rejectionFor(new ConnectException($exception->getMessage(), $request, $exception));
                }
            }

            return Create::promiseFor(new PsrResponse(200, [], '123456789'));
        };
        $http = $this->factoryUsing($handler);

        try {
            (new Client('test', maxResponseBytes: 8, http: $http))->usage()->retrieve();
            self::fail('Expected the transfer to be aborted while streaming.');
        } catch (ResponseTooLargeException) {
            self::assertSame(9, $downloadedBytes);
            self::assertTrue($abortedDuringProgress);
        }
    }

    public function test_it_ignores_encoded_content_length_and_caps_the_decoded_body(): void
    {
        $this->http->fake(['*' => Factory::response(['ok' => true], 200, [
            'Content-Length' => '99',
            'Content-Encoding' => 'gzip',
        ])]);

        self::assertSame(
            ['ok' => true],
            (new Client('test', maxResponseBytes: 16, http: $this->http))->usage()->retrieve(),
        );
    }

    public function test_it_treats_every_non_2xx_response_as_an_api_error(): void
    {
        $this->http->fakeSequence('*')
            ->push('', 101)
            ->push('', 302, ['Location' => 'https://attacker.example/collect']);

        foreach ([101, 302] as $status) {
            try {
                (new Client('test', http: $this->http))->usage()->retrieve();
                self::fail("Expected status {$status} to raise an API exception.");
            } catch (ApiException $exception) {
                self::assertSame($status, $exception->status);
            }
        }
    }

    public function test_it_does_not_follow_redirects_or_forward_credentials(): void
    {
        $this->http->fake([
            'https://api.viapost.io/*' => Factory::response('', 302, ['Location' => 'https://attacker.example/collect']),
            'https://attacker.example/*' => Factory::response(['stolen' => true]),
        ]);

        try {
            (new Client('secret', http: $this->http))->usage()->retrieve();
            self::fail('Expected a redirect response to raise an API exception.');
        } catch (ApiException $exception) {
            self::assertSame(302, $exception->status);
            $this->http->assertSentCount(1);
        }
    }

    public function test_protected_headers_cannot_be_overridden_with_alternative_casing(): void
    {
        $this->http->fake(['*' => Factory::response(['ok' => true])]);
        $client = new Client('protected-key', http: $this->http);

        $client->request('GET', '/v1/usage', headers: [
            'aUtHoRiZaTiOn' => 'Bearer attacker',
            'AcCePt' => 'text/plain',
            'AcCePt-EnCoDiNg' => 'gzip',
            'uSeR-aGeNt' => 'attacker-client',
            'hOsT' => 'attacker.example',
            'cOnTeNt-LeNgTh' => '999',
            'TrAnSfEr-EnCoDiNg' => 'chunked',
            'CoNnEcTiOn' => 'keep-alive',
            'KeEp-AlIvE' => 'timeout=5',
            'PrOxY-AuThOrIzAtIoN' => 'Basic attacker',
            'PrOxY-AuThEnTiCaTe' => 'Basic',
            'Te' => 'trailers',
            'TrAiLeR' => 'X-Checksum',
            'UpGrAdE' => 'websocket',
        ]);

        $this->http->assertSent(static function (Request $request): bool {
            self::assertSame(['Bearer protected-key'], $request->header('Authorization'));
            self::assertSame(['application/json'], $request->header('Accept'));
            self::assertSame(['identity'], $request->header('Accept-Encoding'));
            self::assertSame(['viapost-laravel/0.2.1'], $request->header('User-Agent'));
            self::assertSame(['api.viapost.io'], $request->header('Host'));
            self::assertSame([], $request->header('Content-Length'));
            self::assertSame([], $request->header('Transfer-Encoding'));
            self::assertSame([], $request->header('Connection'));
            self::assertSame([], $request->header('Keep-Alive'));
            self::assertSame([], $request->header('Proxy-Authorization'));
            self::assertSame([], $request->header('Proxy-Authenticate'));
            self::assertSame([], $request->header('TE'));
            self::assertSame([], $request->header('Trailer'));
            self::assertSame([], $request->header('Upgrade'));

            return true;
        });
    }

    public function test_it_disables_automatic_content_decoding_before_the_response_limit_boundary(): void
    {
        $handler = new MockHandler([new PsrResponse(200, [], '{"ok":true}')]);
        $http = $this->factoryUsing($handler);

        self::assertSame(['ok' => true], (new Client('test', http: $http))->usage()->retrieve());

        $options = $handler->getLastOptions();
        self::assertFalse($options['decode_content'] ?? null);
        self::assertSame('identity', $handler->getLastRequest()?->getHeaderLine('Accept-Encoding'));
    }

    public function test_it_never_combines_bearer_authentication_with_cookies_or_csrf_headers(): void
    {
        $http = (new Factory)->globalOptions([
            'cookies' => CookieJar::fromArray(['session' => 'tenant-b'], 'api.viapost.io'),
            'headers' => [
                'Authorization' => 'Bearer tenant-b-key',
                'Cookie' => 'global-session=tenant-b',
                'Host' => 'tenant-b.example',
                'User-Agent' => 'tenant-b-client',
                'X-ViaPost-Csrf' => 'global-csrf',
                'X-CSRF-Token' => 'global-standard-csrf',
            ],
        ]);
        $http->globalRequestMiddleware(static fn (RequestInterface $request): RequestInterface => $request
            ->withAddedHeader('Cookie', 'middleware-session=tenant-b')
            ->withHeader('X-XSRF-Token', 'middleware-xsrf'));
        $http->preventStrayRequests();
        $http->fake(['*' => Factory::response(['ok' => true])]);

        (new Client('tenant-a-key', http: $http))->request('GET', '/v1/usage', headers: [
            'cOoKiE' => 'request-session=tenant-b',
            'sEt-CoOkIe' => 'request-session=tenant-b',
            'x-ViApOsT-cSrF' => 'request-csrf',
            'X-cSrF-ToKeN' => 'request-standard-csrf',
            'x-XsRf-ToKeN' => 'request-xsrf',
        ]);

        $http->assertSent(static function (Request $request): bool {
            self::assertSame(['Bearer tenant-a-key'], $request->header('Authorization'));
            self::assertSame(['api.viapost.io'], $request->header('Host'));
            self::assertSame(['viapost-laravel/0.2.1'], $request->header('User-Agent'));
            self::assertSame([], $request->header('Cookie'));
            self::assertSame([], $request->header('Set-Cookie'));
            self::assertSame([], $request->header('X-ViaPost-Csrf'));
            self::assertSame([], $request->header('X-CSRF-Token'));
            self::assertSame([], $request->header('X-XSRF-Token'));

            return true;
        });
    }

    public function test_it_preserves_api_error_context(): void
    {
        $body = ['error' => ['code' => 'not_found', 'message' => 'Message not found', 'details' => ['id' => 'missing']]];
        $this->http->fake(['*' => Factory::response($body, 404, ['X-Request-Id' => 'req_123'])]);

        try {
            (new Client('test', http: $this->http))->messages()->retrieve('missing');
            self::fail('Expected an API exception.');
        } catch (ApiException $exception) {
            self::assertSame('Message not found', $exception->getMessage());
            self::assertSame(404, $exception->status);
            self::assertSame('req_123', $exception->requestId);
            self::assertSame('GET', $exception->method);
            self::assertSame('https://api.viapost.io/v1/messages/missing', $exception->url);
            self::assertSame($body, $exception->body);
        }
    }

    public function test_it_redacts_api_keys_and_server_secrets_from_api_errors(): void
    {
        $apiKey = 'vp_live_sensitive_key';
        $webhookSecret = 'whsec_sensitive_value';
        $this->http->fake(['*' => Factory::response([
            'error' => [
                'message' => "Request using {$apiKey} failed for secret {$webhookSecret}",
                'details' => [
                    'api_key' => $apiKey,
                    'secret' => $webhookSecret,
                ],
            ],
        ], 400, [
            'Authorization' => 'Bearer '.$apiKey,
            'X-Debug-Token' => $webhookSecret,
        ])]);

        try {
            (new Client($apiKey, http: $this->http))->usage()->retrieve();
            self::fail('Expected an API exception.');
        } catch (ApiException $exception) {
            $serialized = serialize([
                $exception->getMessage(),
                $exception->body,
                $exception->headers,
            ]);
            self::assertStringNotContainsString($apiKey, $serialized);
            self::assertStringNotContainsString($webhookSecret, $serialized);
            self::assertStringContainsString('[REDACTED]', $serialized);
        }
    }

    public function test_client_serialization_is_forbidden_without_leaking_the_api_key(): void
    {
        $apiKey = 'vp_live_must_never_be_serialized';
        $client = new Client($apiKey, http: $this->http);

        self::assertStringNotContainsString($apiKey, @var_export($client, true));

        try {
            serialize($client);
            self::fail('Expected client serialization to be forbidden.');
        } catch (\LogicException $exception) {
            self::assertStringNotContainsString($apiKey, $exception->getMessage());
            self::assertStringContainsString('must not be serialized', $exception->getMessage());
        }
    }

    public function test_raw_downloads_use_a_separate_limit_while_json_and_errors_keep_the_small_limit(): void
    {
        $this->http->fakeSequence('*')
            ->push(str_repeat('r', 32), 200, ['Content-Type' => 'message/rfc822'])
            ->push(['value' => str_repeat('j', 32)], 200)
            ->push(['error' => ['message' => str_repeat('e', 32)]], 400)
            ->push(str_repeat('r', 65), 200, ['Content-Type' => 'message/rfc822']);
        $client = new Client('test', maxResponseBytes: 16, maxRawResponseBytes: 64, http: $this->http);

        self::assertSame(str_repeat('r', 32), $client->messages()->raw('raw-ok'));

        try {
            $client->usage()->retrieve();
            self::fail('Expected JSON response limit to remain enforced.');
        } catch (ResponseTooLargeException $exception) {
            self::assertStringContainsString('16 bytes', $exception->getMessage());
        }

        try {
            $client->messages()->raw('raw-error');
            self::fail('Expected raw endpoint errors to use the JSON/error limit.');
        } catch (ResponseTooLargeException $exception) {
            self::assertStringContainsString('16 bytes', $exception->getMessage());
        }

        try {
            $client->messages()->raw('raw-too-large');
            self::fail('Expected the raw response limit to be enforced.');
        } catch (ResponseTooLargeException $exception) {
            self::assertStringContainsString('64 bytes', $exception->getMessage());
        }
    }

    public function test_raw_transfer_callbacks_use_the_large_limit_only_for_successful_responses(): void
    {
        $handler = new MockHandler([
            new PsrResponse(200, ['Content-Length' => '32'], str_repeat('r', 32)),
            new PsrResponse(400, ['Content-Length' => '32'], str_repeat('e', 32)),
        ]);
        $client = new Client(
            'test',
            maxResponseBytes: 16,
            maxRawResponseBytes: 64,
            http: $this->factoryUsing($handler),
        );

        self::assertSame(str_repeat('r', 32), $client->messages()->raw('raw-ok'));

        $this->expectException(ResponseTooLargeException::class);
        $this->expectExceptionMessage('16 bytes');
        $client->messages()->raw('raw-error');
    }

    public function test_webhook_secret_responses_only_reveal_secrets_through_explicit_access(): void
    {
        $secret = str_repeat('s', 43);
        $endpoint = ['id' => 'webhook-id', 'url' => 'https://example.com/hook'];
        $this->http->fakeSequence('*')
            ->push(['endpoint' => $endpoint, 'secret' => $secret])
            ->push(['endpoint' => $endpoint, 'secret' => $secret, 'rotated_at' => '2026-09-16T12:00:00Z']);
        $client = new Client('test', http: $this->http);

        $created = $client->webhooks()->create([
            'url' => 'https://example.com/hook',
            'event_types' => ['delivered'],
        ]);
        $rotated = $client->webhooks()->rotateSecret('webhook-id', 'rotate-1');

        foreach ([$created, $rotated] as $response) {
            self::assertInstanceOf(WebhookSecretResponse::class, $response);
            self::assertSame($secret, $response->secret());
            self::assertStringNotContainsString($secret, (string) json_encode($response, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString($secret, print_r($response, true));
            self::assertStringNotContainsString($secret, serialize($response));
            self::assertStringNotContainsString($secret, var_export($response, true));
            $monologOutput = (new JsonFormatter)->format(new LogRecord(
                new \DateTimeImmutable,
                'test',
                Level::Info,
                'Webhook secret response',
                ['response' => $response],
            ));
            self::assertStringNotContainsString($secret, $monologOutput);
        }

        self::assertSame($endpoint, $created->endpoint());
        self::assertNull($created->rotatedAt());
        self::assertSame('2026-09-16T12:00:00Z', $rotated->rotatedAt());
    }

    public function test_it_uses_request_id_from_the_error_body_as_fallback(): void
    {
        $this->http->fake(['*' => Factory::response([
            'error' => ['message' => 'Missing', 'request_id' => 'req_body'],
        ], 404)]);

        try {
            (new Client('test', http: $this->http))->messages()->retrieve('missing');
            self::fail('Expected an API exception.');
        } catch (ApiException $exception) {
            self::assertSame('req_body', $exception->requestId);
        }
    }

    public function test_it_retries_get_on_429_and_5xx(): void
    {
        $this->http->fakeSequence('*')
            ->push(['error' => ['message' => 'busy']], 503, ['Retry-After' => '0'])
            ->push(['error' => ['message' => 'limited']], 429, ['Retry-After' => '0'])
            ->push(['messages' => [['id' => 'message-1']]]);

        $result = (new Client('test', maxRetries: 2, retryBaseDelayMs: 0, retryMaxDelayMs: 0, http: $this->http))
            ->messages()->list();

        self::assertArrayHasKey('messages', $result);
        self::assertIsArray($result['messages']);
        self::assertArrayHasKey(0, $result['messages']);
        self::assertIsArray($result['messages'][0]);
        self::assertSame('message-1', $result['messages'][0]['id']);
        $this->http->assertSentCount(3);
    }

    public function test_it_honors_and_caps_an_http_date_retry_after_value(): void
    {
        $this->http->fakeSequence('*')
            ->push(['error' => ['message' => 'limited']], 429, [
                'Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 3600),
            ])
            ->push(['messages' => []]);

        $startedAt = hrtime(true);
        (new Client(
            'test',
            maxRetries: 1,
            retryBaseDelayMs: 0,
            retryMaxDelayMs: 100,
            http: $this->http,
        ))->messages()->list();
        $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

        self::assertGreaterThanOrEqual(80, $elapsedMilliseconds);
        $this->http->assertSentCount(2);
    }

    public function test_it_retries_head_on_5xx(): void
    {
        $this->http->fakeSequence('*')
            ->push('', 503, ['Retry-After' => '0'])
            ->push('', 200);

        (new Client('test', maxRetries: 1, retryBaseDelayMs: 0, http: $this->http))
            ->request('HEAD', '/v1/status');

        $this->http->assertSentCount(2);
    }

    public function test_it_never_retries_a_mutating_request(): void
    {
        $this->http->fake(['*' => Factory::response(['error' => ['message' => 'failed']], 503)]);

        try {
            (new Client('test', maxRetries: 5, retryBaseDelayMs: 0, http: $this->http))->send()->create([
                'from' => 'hello@example.com',
                'to' => ['person@example.net'],
                'subject' => 'Hello',
                'text' => 'Hello from ViaPost',
            ]);
            self::fail('Expected an API exception.');
        } catch (ApiException) {
            $this->http->assertSentCount(1);
        }
    }

    public function test_it_wraps_connection_failures_without_exposing_the_api_key(): void
    {
        $this->http->fake(static fn () => throw new LaravelConnectionException('socket closed'));

        try {
            (new Client('secret-key-never-in-errors', http: $this->http))->usage()->retrieve();
            self::fail('Expected a connection exception.');
        } catch (ConnectionException $exception) {
            self::assertStringNotContainsString('secret-key-never-in-errors', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function test_it_maps_network_timeouts_to_a_typed_exception(): void
    {
        $this->http->fake(static fn () => throw new LaravelConnectionException('cURL error 28: Operation timed out'));

        try {
            (new Client('test', timeout: 7, http: $this->http))->usage()->retrieve();
            self::fail('Expected a timeout exception.');
        } catch (TimeoutException $exception) {
            self::assertSame(7, $exception->timeoutSeconds);
        }
    }

    /** @param callable(RequestInterface, array<string, mixed>): mixed $handler */
    private function factoryUsing(callable $handler): Factory
    {
        return new class($handler) extends Factory
        {
            private readonly Closure $handler;

            public function __construct(callable $handler)
            {
                parent::__construct();
                $this->handler = Closure::fromCallable($handler);
            }

            protected function newPendingRequest(): PendingRequest
            {
                return parent::newPendingRequest()->setHandler($this->handler);
            }
        };
    }
}

<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Tests\Unit;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ViaPost\Laravel\Client;
use ViaPost\Laravel\Responses\WebhookSecretResponse;

final class ResourcesTest extends TestCase
{
    private Factory $http;

    private Client $client;

    /** @var list<array{string, string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new Factory;
        $this->http->preventStrayRequests();
        $this->http->fake(function (Request $request) {
            $this->calls[] = [$request->method(), parse_url($request->url(), PHP_URL_PATH) ?: ''];

            return Factory::response([]);
        });
        $this->client = new Client('test', http: $this->http);
    }

    public function test_it_maps_the_complete_ergonomic_surface_to_public_endpoints(): void
    {
        $body = ['name' => 'example'];

        $this->client->send()->create(['from' => 'hello@example.com', 'to' => ['person@example.com']]);
        $this->client->messages()->list();
        $this->client->messages()->retrieve('m/id');
        $this->client->messages()->raw('m/id');
        $this->client->messages()->events('m/id');
        $this->client->messages()->engagement();
        $this->client->messages()->metrics();
        $this->client->messages()->timeseries();
        $this->client->domains()->list();
        $this->client->domains()->create($body);
        $this->client->domains()->retrieve('d/id');
        $this->client->domains()->delete('d/id');
        $this->client->domains()->dns('d/id');
        $this->client->domains()->verify('d/id');
        $this->client->domains()->rotateDkim('d/id');
        $this->client->templates()->list();
        $this->client->templates()->create($body);
        $this->client->templates()->retrieve('t/id');
        $this->client->templates()->delete('t/id');
        $this->client->templates()->archive('t/id');
        $this->client->templates()->createAsset('t/id', $body);
        $this->client->templates()->updateDraft('t/id', $body);
        $this->client->templates()->duplicate('t/id');
        $this->client->templates()->preview('t/id', $body);
        $this->client->templates()->publish('t/id', $body);
        $this->client->templates()->versions('t/id');
        $this->client->templates()->version('t/id', 'v/id');
        $this->client->templates()->revert('t/id', 'v/id', $body);
        $this->client->webhooks()->list();
        $this->client->webhooks()->create(['url' => 'https://example.com/hook', 'event_types' => ['delivered']]);
        $this->client->webhooks()->update('w/id', ['expected_version' => 1, 'enabled' => true]);
        $this->client->webhooks()->deliveries('w/id');
        $this->client->webhooks()->delivery('w/id', 'delivery/id');
        $this->client->webhooks()->replay('w/id', 'delivery/id', 'replay-1');
        $this->client->webhooks()->test('w/id', 'test-1');
        $this->client->webhooks()->rotateSecret('w/id', 'rotate-1');
        $this->client->webhooks()->delete('w/id');
        $this->client->inboundMessages()->list();
        $this->client->inboundMessages()->retrieve('i/id');
        $this->client->inboundMessages()->raw('i/id');
        $this->client->suppressions()->list();
        $this->client->suppressions()->create($body);
        $this->client->suppressions()->retrieve('s/id');
        $this->client->suppressions()->import("email,reason,expires_at,note\nuser@example.com,manual,,test\n");
        $this->client->suppressions()->export();
        $this->client->suppressions()->release('s/id', [
            'expected_version' => 1,
            'acknowledge' => true,
            'justification' => 'Solicitação confirmada pelo titular.',
        ]);
        $this->client->automations()->list();
        $this->client->automations()->create($body);
        $this->client->automations()->retrieve('a/id');
        $this->client->automations()->update('a/id', $body);
        $this->client->automations()->delete('a/id');
        $this->client->automations()->activate('a/id');
        $this->client->automations()->disable('a/id');
        $this->client->automations()->updateDraft('a/id', $body);
        $this->client->automations()->duplicate('a/id');
        $this->client->automations()->runs('a/id');
        $this->client->automations()->run('a/id', 'r/id');
        $this->client->automations()->cancelRun('a/id', 'r/id');
        $this->client->usage()->retrieve();

        self::assertSame([
            ['POST', '/v1/send'],
            ['GET', '/v1/messages'],
            ['GET', '/v1/messages/m%2Fid'],
            ['GET', '/v1/messages/m%2Fid/raw'],
            ['GET', '/v1/messages/m%2Fid/events'],
            ['GET', '/v1/messages/engagement'],
            ['GET', '/v1/messages/metrics'],
            ['GET', '/v1/messages/timeseries'],
            ['GET', '/v1/domains'],
            ['POST', '/v1/domains'],
            ['GET', '/v1/domains/d%2Fid'],
            ['DELETE', '/v1/domains/d%2Fid'],
            ['GET', '/v1/domains/d%2Fid/dns'],
            ['POST', '/v1/domains/d%2Fid/verify'],
            ['POST', '/v1/domains/d%2Fid/dkim/rotate'],
            ['GET', '/v1/templates'],
            ['POST', '/v1/templates'],
            ['GET', '/v1/templates/t%2Fid'],
            ['DELETE', '/v1/templates/t%2Fid'],
            ['POST', '/v1/templates/t%2Fid/archive'],
            ['POST', '/v1/templates/t%2Fid/assets'],
            ['PATCH', '/v1/templates/t%2Fid/draft'],
            ['POST', '/v1/templates/t%2Fid/duplicate'],
            ['POST', '/v1/templates/t%2Fid/preview'],
            ['POST', '/v1/templates/t%2Fid/publish'],
            ['GET', '/v1/templates/t%2Fid/versions'],
            ['GET', '/v1/templates/t%2Fid/versions/v%2Fid'],
            ['POST', '/v1/templates/t%2Fid/versions/v%2Fid/revert'],
            ['GET', '/v1/webhooks'],
            ['POST', '/v1/webhooks'],
            ['PATCH', '/v1/webhooks/w%2Fid'],
            ['GET', '/v1/webhooks/w%2Fid/deliveries'],
            ['GET', '/v1/webhooks/w%2Fid/deliveries/delivery%2Fid'],
            ['POST', '/v1/webhooks/w%2Fid/deliveries/delivery%2Fid/replay'],
            ['POST', '/v1/webhooks/w%2Fid/test'],
            ['POST', '/v1/webhooks/w%2Fid/secret/rotate'],
            ['DELETE', '/v1/webhooks/w%2Fid'],
            ['GET', '/v1/inbound-messages'],
            ['GET', '/v1/inbound-messages/i%2Fid'],
            ['GET', '/v1/inbound-messages/i%2Fid/raw'],
            ['GET', '/v1/suppressions'],
            ['POST', '/v1/suppressions'],
            ['GET', '/v1/suppressions/s%2Fid'],
            ['POST', '/v1/suppressions/import'],
            ['GET', '/v1/suppressions/export'],
            ['POST', '/v1/suppressions/s%2Fid/release'],
            ['GET', '/v1/automations'],
            ['POST', '/v1/automations'],
            ['GET', '/v1/automations/a%2Fid'],
            ['PATCH', '/v1/automations/a%2Fid'],
            ['DELETE', '/v1/automations/a%2Fid'],
            ['POST', '/v1/automations/a%2Fid/activate'],
            ['POST', '/v1/automations/a%2Fid/disable'],
            ['PATCH', '/v1/automations/a%2Fid/draft'],
            ['POST', '/v1/automations/a%2Fid/duplicate'],
            ['GET', '/v1/automations/a%2Fid/runs'],
            ['GET', '/v1/automations/a%2Fid/runs/r%2Fid'],
            ['POST', '/v1/automations/a%2Fid/runs/r%2Fid/cancel'],
            ['GET', '/v1/usage'],
        ], $this->calls);
    }

    public function test_send_sets_and_validates_the_idempotency_key_and_normalizes_lists(): void
    {
        $this->http->fake(function (Request $request) {
            self::assertTrue($request->hasHeader('Idempotency-Key', 'order-123'));

            return Factory::response(['accepted' => null, 'rejected' => null]);
        });

        $result = $this->client->send()->create(['from' => 'hello@example.com', 'to' => ['person@example.com']], 'order-123');

        self::assertSame([], $result['accepted']);
        self::assertSame([], $result['rejected']);
    }

    public function test_raw_downloads_preserve_bytes_and_request_the_contract_media_type(): void
    {
        $rawMessage = "From: sender@example.com\r\n\r\nExact bytes\r\n";
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(function (Request $request) use ($rawMessage) {
            self::assertTrue($request->hasHeader('Accept', 'message/rfc822'));

            return Factory::response($rawMessage, 200, ['Content-Type' => 'message/rfc822']);
        });
        $client = new Client('test', http: $http);

        self::assertSame($rawMessage, $client->messages()->raw('message'));
        self::assertSame($rawMessage, $client->inboundMessages()->raw('inbound'));
    }

    public function test_suppression_csv_transport_uses_explicit_media_types(): void
    {
        $csv = "email,reason,expires_at,note\nuser@example.com,manual,,test\n";
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(function (Request $request) use ($csv) {
            if ($request->method() === 'POST') {
                self::assertSame($csv, $request->body());
                self::assertTrue($request->hasHeader('Content-Type', 'text/csv; charset=UTF-8'));
                self::assertTrue($request->hasHeader('Accept', 'application/json'));

                return Factory::response(['created' => 1]);
            }

            self::assertTrue($request->hasHeader('Accept', 'text/csv'));

            return Factory::response("email,reason,state\n", 200, ['Content-Type' => 'text/csv']);
        });
        $client = new Client('test', http: $http);

        self::assertSame(['created' => 1], $client->suppressions()->import($csv));
        self::assertSame("email,reason,state\n", $client->suppressions()->export());
    }

    public function test_webhook_actions_send_an_empty_json_object_and_idempotency_key(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(function (Request $request) {
            self::assertSame('{}', $request->body());
            self::assertTrue($request->hasHeader('Content-Type', 'application/json'));
            self::assertTrue($request->hasHeader('Idempotency-Key'));

            return Factory::response(['accepted' => true]);
        });
        $client = new Client('test', http: $http);

        self::assertSame(['accepted' => true], $client->webhooks()->replay('webhook', 'delivery', 'replay-1'));
        self::assertSame(['accepted' => true], $client->webhooks()->test('webhook', 'test-1'));
        self::assertInstanceOf(WebhookSecretResponse::class, $client->webhooks()->rotateSecret('webhook', 'rotate-1'));
    }

    public function test_send_rejects_invalid_idempotency_keys_before_network_io(): void
    {
        foreach (['', str_repeat('x', 256), 'contains space', 'pedido-é', "prefix\u{0000}suffix", "prefix\u{200E}suffix", "\xFF"] as $key) {
            try {
                $this->client->send()->create([], $key);
                self::fail('Expected an invalid idempotency key exception.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('idempotency key', $exception->getMessage());
            }
        }

        $this->http->assertNothingSent();
    }

    public function test_resources_reject_empty_and_url_normalizing_path_segments(): void
    {
        foreach (['', '.', '..'] as $id) {
            try {
                $this->client->messages()->retrieve($id);
                self::fail('Expected an invalid path parameter exception.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('messageId', $exception->getMessage());
            }
        }

        $this->http->assertNothingSent();
    }

    public function test_critical_openapi_constraints_are_rejected_before_network_io(): void
    {
        $invalidCalls = [
            fn () => $this->client->send()->create(['from' => 'a', 'to' => []]),
            fn () => $this->client->send()->create(['from' => 'a', 'to' => array_fill(0, 51, 'b')]),
            fn () => $this->client->send()->create(['from' => 'a', 'to' => ['b'], 'cc' => array_fill(0, 51, 'c')]),
            fn () => $this->client->send()->create(['from' => 'a', 'to' => ['b'], 'variables' => array_fill(0, 101, 'v')]),
            fn () => $this->client->send()->create(['from' => 'a', 'to' => ['b'], 'attachments' => array_fill(0, 11, [])]),
            fn () => $this->client->webhooks()->create(['url' => 'file:///tmp/hook', 'event_types' => ['delivered']]),
            fn () => $this->client->webhooks()->create(['url' => 'http://example.com/hook', 'event_types' => ['delivered']]),
            fn () => $this->client->webhooks()->create(['url' => 'https://example.com/hook', 'event_types' => []]),
            fn () => $this->client->webhooks()->create(['url' => 'https://example.com/hook', 'event_types' => ['delivered', 'delivered']]),
            fn () => $this->client->webhooks()->create(['url' => 'https://example.com/hook', 'event_types' => ['delivered'], 'unknown' => true]),
            fn () => $this->client->webhooks()->update('webhook', ['url' => 'file:///tmp/hook']),
            fn () => $this->client->webhooks()->update('webhook', ['expected_version' => 1]),
            fn () => $this->client->webhooks()->update('webhook', ['expected_version' => 0, 'enabled' => true]),
            fn () => $this->client->webhooks()->update('webhook', ['expected_version' => 1, 'url' => 'https://example.com/hook']),
            fn () => $this->client->webhooks()->update('webhook', ['expected_version' => 1, 'unknown' => true]),
            fn () => $this->client->webhooks()->update('webhook', ['event_types' => []]),
            fn () => $this->client->webhooks()->update('webhook', ['expected_version' => 1, 'event_types' => ['open', 'open']]),
            fn () => $this->client->webhooks()->update('webhook', ['expected_version' => 1, 'max_attempts' => 0]),
            fn () => $this->client->webhooks()->update('webhook', ['expected_version' => 1, 'max_attempts' => 21]),
            fn () => $this->client->webhooks()->test('webhook', 'contains space'),
            fn () => $this->client->suppressions()->import(str_repeat('x', 2_097_153)),
            fn () => $this->client->suppressions()->release('suppression', []),
            fn () => $this->client->suppressions()->release('suppression', ['expected_version' => 0, 'acknowledge' => true, 'justification' => 'Justificativa válida.']),
            fn () => $this->client->suppressions()->release('suppression', ['expected_version' => 1, 'acknowledge' => false, 'justification' => 'Justificativa válida.']),
            fn () => $this->client->suppressions()->release('suppression', ['expected_version' => 1, 'acknowledge' => true, 'justification' => 'curta']),
            fn () => $this->client->suppressions()->release('suppression', ['expected_version' => 1, 'acknowledge' => true, 'justification' => str_repeat('x', 501)]),
            fn () => $this->client->suppressions()->release('suppression', ['expected_version' => 1, 'acknowledge' => true, 'justification' => 'Justificativa válida.', 'unknown' => true]),
            fn () => $this->client->templates()->updateDraft('template', ['variables' => array_fill(0, 101, [])]),
            fn () => $this->client->templates()->updateDraft('template', ['variables' => [], 'expected_version_id' => 'version']),
            fn () => $this->client->templates()->preview('template', ['variables' => array_fill(0, 101, 'v')]),
        ];

        foreach ($invalidCalls as $call) {
            try {
                $call();
                self::fail('Expected an invalid request exception.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $this->http->assertNothingSent();
    }

    public function test_webhook_urls_reject_local_or_non_public_literal_destinations(): void
    {
        $unsafeUrls = [
            'https://user:password@example.com/hook',
            'https://example.com/hook#fragment',
            'https://exa mple.com/hook',
            'https://example.com:99999/hook',
            'https://localhost/hook',
            'https://hooks.localhost/hook',
            'https://127.0.0.1/hook',
            'https://127.1/hook',
            'https://2130706433/hook',
            'https://0x7f000001/hook',
            'https://10.0.0.1/hook',
            'https://169.254.10.20/hook',
            'https://0.0.0.0/hook',
            'https://224.0.0.1/hook',
            'https://[::1]/hook',
            'https://[fc00::1]/hook',
            'https://[fe80::1]/hook',
            'https://[::]/hook',
            'https://[ff02::1]/hook',
        ];

        foreach ($unsafeUrls as $url) {
            try {
                $this->client->webhooks()->create(['url' => $url, 'event_types' => ['delivered']]);
                self::fail("Expected unsafe webhook URL to be rejected: {$url}");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $this->http->assertNothingSent();
    }
}

<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Tests\Unit;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ViaPost\Laravel\Client;

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
        $this->client->webhooks()->delete('w/id');
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
            ['DELETE', '/v1/webhooks/w%2Fid'],
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
            fn () => $this->client->webhooks()->create(['url' => 'https://example.com/hook', 'event_types' => []]),
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
}

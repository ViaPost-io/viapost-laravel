# ViaPost Laravel SDK

SDK oficial server-side para a API ViaPost, com integração nativa ao Laravel 12 e 13.

> **Laravel 11 não é suportado:** o suporte de segurança do framework terminou e não há patch 11.x
> para [GHSA-5vg9-5847-vvmq](https://github.com/advisories/GHSA-5vg9-5847-vvmq). Migre para Laravel
> 12 antes de atualizar para a próxima versão principal do SDK; siga o
> [guia oficial de upgrade para Laravel 12](https://laravel.com/docs/12.x/upgrade).

Requer PHP 8.2 ou superior.

> A versão publicada mais recente é a `0.3.x`. A `0.4.0` ainda não foi publicada: migre de
> Laravel 11 para Laravel 12 antes de adotá-la e consulte o changelog para as mudanças
> incompatíveis.

## Instalação pelo GitHub

O Packagist ainda não é necessário. O Composer pode resolver tags públicas diretamente deste
repositório VCS:

```bash
composer config repositories.viapost vcs https://github.com/ViaPost-io/viapost-laravel
composer require viapost/laravel-sdk:^0.3
```

Quando o pacote for cadastrado no Packagist, somente o segundo comando será necessário. O Laravel
descobre automaticamente o service provider e a facade.

Publique a configuração, se quiser customizá-la:

```bash
php artisan vendor:publish --tag=viapost-config
```

No `.env`:

```dotenv
VIAPOST_API_KEY=vp_live_replace_me
```

Nunca exponha essa chave no frontend, em logs ou no controle de versão.

## Uso

```php
use ViaPost\Laravel\Facades\ViaPost;

$result = ViaPost::send()->create([
    'from' => 'hello@seu-dominio.com',
    'to' => ['cliente@example.com'],
    'subject' => 'Olá!',
    'text' => 'Enviado com ViaPost.',
], idempotencyKey: 'pedido-123');

$messages = ViaPost::messages()->list(['status' => 'delivered', 'limit' => 25]);

$eml = ViaPost::messages()->raw('message-uuid');
$inbound = ViaPost::inboundMessages()->list(['has_attachments' => true]);

$csv = "email,reason,expires_at,note\ncliente@example.com,manual,,Solicitação do cliente\n";
$result = ViaPost::suppressions()->import($csv);
$export = ViaPost::suppressions()->export(['state' => 'active']);

$deliveries = ViaPost::webhooks()->deliveries('webhook-uuid', ['limit' => 25]);
$replay = ViaPost::webhooks()->replay('webhook-uuid', 'delivery-uuid', 'replay-pedido-123');
$createdWebhook = ViaPost::webhooks()->create([
    'url' => 'https://hooks.example.com/viapost',
    'event_types' => ['delivered', 'hard_bounce'],
]);
$oneTimeSecret = $createdWebhook->secret(); // leia e armazene somente neste ponto
```

Também é possível injetar o singleton:

```php
use ViaPost\Laravel\Client;

final class SendReceipt
{
    public function __construct(private Client $viapost) {}

    public function __invoke(array $email): array
    {
        return $this->viapost->send()->create($email);
    }
}
```

Recursos disponíveis: `send`, `contacts`, `messages`, `inboundMessages`, `suppressions`, `domains`,
`segments`, `templates`, `webhooks`, `automations` e `usage`. Os métodos e payloads correspondem ao snapshot
`openapi.yaml` incluído no pacote. Downloads RFC 5322, importação/exportação CSV de supressões,
entregas/replay/teste/rotação de webhooks e controle otimista de endpoints estão disponíveis nos
respectivos recursos.
Durante o beta, payloads e respostas são arrays associativos idiomáticos do Laravel, exceto as
respostas de criação/rotação de webhook: `WebhookSecretResponse` exige `secret()` para revelar o
segredo de uso único e o omite de JSON, debug e logs. O SDK valida
localmente os limites críticos; as demais regras do contrato são validadas pela API ViaPost.

Erros HTTP lançam `ViaPost\Laravel\Exceptions\ApiException`, preservando `status`, `requestId`,
`method`, `url`, `body` e `headers`, mas redigindo API keys, tokens, cookies e secrets que o servidor
eventualmente ecoe. Falhas de rede e respostas grandes ou inválidas têm classes tipadas próprias.
O cliente repete apenas `GET`/`HEAD` em `429` ou `5xx`; mutações nunca são repetidas automaticamente.

HTTP sem TLS é aceito somente para hosts loopback explícitos (`localhost`, `127.0.0.0/8`, `::1`).
Destinos de webhook exigem HTTPS e o SDK rejeita credenciais, fragmentos e endereços IP locais ou
não públicos evidentes. A API continua sendo a autoridade final e também resolve/valida o destino
contra DNS rebinding e mudanças de resolução.

Respostas JSON/erros são limitados a 10 MiB por padrão; downloads raw RFC 5322 têm limite separado
de 40 MiB. Customize com `VIAPOST_MAX_RESPONSE_BYTES` e `VIAPOST_MAX_RAW_RESPONSE_BYTES`.

Os endpoints públicos de inscrição da página de status não fazem parte deste cliente autenticado
por API Key. Eles implementam double opt-in por e-mail e devem ser usados pela interface pública em
`status.viapost.io`, evitando misturar credenciais server-side com o fluxo anônimo de assinatura.

---

## English

Official server-side SDK for the ViaPost API with native Laravel 12 and 13 integration.

> **Laravel 11 is unsupported:** its security support has ended and no 11.x patch exists for
> [GHSA-5vg9-5847-vvmq](https://github.com/advisories/GHSA-5vg9-5847-vvmq). Upgrade to Laravel 12
> before updating to the next SDK major version; follow Laravel's
> [official Laravel 12 upgrade guide](https://laravel.com/docs/12.x/upgrade).

Requires PHP 8.2 or newer.

> The latest published version is `0.3.x`. Version `0.4.0` has not been published yet; upgrade
> from Laravel 11 to Laravel 12 before adopting it and consult the changelog for breaking changes.

Install directly from public GitHub tags until Packagist is configured:

```bash
composer config repositories.viapost vcs https://github.com/ViaPost-io/viapost-laravel
composer require viapost/laravel-sdk:^0.3
```

Set `VIAPOST_API_KEY` in your server-side environment. Laravel auto-discovers the provider and
facade; publish optional configuration with `php artisan vendor:publish --tag=viapost-config`.

The client exposes `send`, `contacts`, `messages`, `inboundMessages`, `suppressions`, `domains`,
`segments`, `templates`, `webhooks`, `automations`, and `usage`, matching the bundled OpenAPI snapshot. See the Portuguese
examples above—the API is the same in either language. During the beta, payloads and responses
remain idiomatic associative arrays, except one-time webhook secret responses, whose secret is
available only through the explicit `secret()` accessor and is omitted from JSON/debug/log output.
Critical constraints are checked locally; the ViaPost API
validates the remaining contract. Public status subscriptions intentionally remain outside this
API-key-authenticated client because they use an anonymous email double-opt-in flow.

## Development

```bash
composer install
composer check
composer audit
```

See [CONTRIBUTING.md](CONTRIBUTING.md), [SECURITY.md](SECURITY.md), and
[docs/TDD.md](docs/TDD.md).

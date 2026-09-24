# Security Policy / Política de Segurança

Supported security fixes target the latest `0.4.x` release while the SDK is in beta.

Report vulnerabilities privately through GitHub Security Advisories for
`ViaPost-io/viapost-laravel`. Do not open a public issue and do not include live API keys, message
contents, customer data, or exploit details in logs.

The SDK is server-side only. Keep `VIAPOST_API_KEY` in a secret manager or protected environment,
rotate any exposed key immediately, and use HTTPS outside explicit loopback development hosts.

Relate vulnerabilidades de forma privada pelo GitHub Security Advisories. Nunca envie chaves reais
ou dados de clientes. O SDK deve ser usado somente no servidor.

# TDD evidence / Evidência de TDD

## Observable behavior plan / Plano de comportamentos observáveis

1. Reject an empty API key, non-absolute URLs, non-HTTP schemes, credential-bearing URLs,
   non-loopback plain HTTP, and invalid numeric limits; accept HTTPS and explicit loopback HTTP.
2. Send Bearer authentication, JSON headers, the SDK user agent, configured timeouts, and query
   parameters through Laravel's HTTP client.
3. Return decoded JSON and `null` for successful empty responses, while rejecting oversized bodies.
4. Raise typed API errors preserving status, request ID, method, URL, headers, and decoded/raw body;
   wrap connection failures without exposing credentials.
5. Retry only GET/HEAD responses on 429/5xx, respecting configured bounds, and never retry mutations.
6. Validate and send a visible-ASCII `Idempotency-Key` on `/v1/send`; normalize nullable result lists.
7. Reject empty/dot path parameters and percent-encode every dynamic path segment.
8. Map send, messages, inbound messages, suppressions, domains, templates, the complete webhook
   operations, automations, and usage to the frozen public API.
9. Auto-discover the provider, bind one client instance, merge/publish config, and expose the facade.
10. Verify the embedded OpenAPI snapshot byte-for-byte against its recorded SHA-256 and compare
    external YAML semantically so harmless serialization differences do not report drift.

## Red → Green log / Registro Red → Green

This log is completed as each focused test is executed. / Este registro é completado à medida que
cada teste focado é executado.

- RED 1 — `vendor/bin/phpunit --filter ConfigurationTest`: 9 tests failed because
  `ViaPost\\Laravel\\Client` did not exist, the expected missing behavior.
- GREEN 1 — the same command passed: 9 tests, 10 assertions.
- RED 2 — `vendor/bin/phpunit --filter TransportTest`: 7 focused tests errored because transport,
  typed errors, and resource accessors did not exist.
- GREEN 2 — the same command passed: 7 tests, 19 assertions.
- RED 3 — `vendor/bin/phpunit --filter ResourcesTest`: the endpoint matrix stopped at the first
  missing method, while idempotency normalization/validation and safe path validation failed.
- GREEN 3 — the same command passed: 4 tests, 13 assertions, covering 43 public calls.
- RED 4 — `vendor/bin/phpunit --filter LaravelIntegrationTest`: 4 tests errored because the
  provider, facade, and package configuration did not exist.
- GREEN 4 — the same command passed: 4 tests, 8 assertions, including `Http::fake` and
  `Http::preventStrayRequests` through the facade.
- RED 5 — `vendor/bin/phpunit --filter ContractSnapshotTest`: failed because `openapi.yaml` did
  not exist.
- GREEN 5 — the snapshot test passed with 2 assertions and the standalone source comparison
  verified SHA-256 `d1f223342ad1ca326ba716af6e508c78594e1b108958cce2ec4a1efd31a9773a`.
- RED 6 — the expanded configuration test exposed four hardening gaps: API-key header controls,
  base URL query/fragment ambiguity, and an invalid `127.*` lookalike.
- GREEN 6 — `vendor/bin/phpunit --filter ConfigurationTest` passed: 13 tests, 15 assertions.
- RED 7 — five focused transport edge tests produced four expected failures/errors: advertised
  body size was ignored, 1xx/3xx were treated as success, redirects were not explicitly blocked,
  and timeouts were generic connection failures. Authorization protection was already green.
- GREEN 7 — the same five focused tests passed with 7 assertions after hardening the transport.
- RED 8 — six security-focused cases produced four failures and two errors: mixed-case protected
  headers were duplicated, API/idempotency keys accepted non-visible characters, and no transfer
  callbacks existed to abort advertised or streamed oversized responses.
- GREEN 8 — the same focused cases passed after case-insensitive header filtering, visible-ASCII
  validation, and Guzzle `on_headers`/`progress` abort guards with typed error mapping.
- RED 9 — the HTTP-date `Retry-After` test completed below its 80 ms bound because the date was
  ignored and the configured zero-delay exponential fallback ran instead.
- GREEN 9 — the focused test passed after parsing HTTP dates and capping their delay at the
  configured 100 ms maximum; numeric and exponential arithmetic was hardened against overflow.
- RED 10 — the final three transport-security cases failed as intended: hop-by-hop/proxy headers
  remained injectable, encoded `Content-Length` caused a false positive, and `progress` returned
  instead of throwing, which Guzzle 7 ignores for transfer-abort purposes.
- GREEN 10 — the same cases passed with 19 assertions after filtering all protected headers,
  limiting advertised identity bodies only, retaining the decoded-body cap, and throwing from
  `progress`. Real loopback streaming checks confirmed typed early aborts on Guzzle 7.15.5 and
  8.2.0.
- RED 11 — a stateless-auth sentinel observed `session=tenant-b` on a Bearer-authenticated request
  when a shared Laravel `Factory` supplied a global cookie jar and mixed-case cookie/CSRF headers.
- GREEN 11 — the sentinel passed with 9 assertions after forcing `cookies => false` and applying a
  final request boundary that strips protected headers injected by request arguments, global
  options, or global middleware before restoring only SDK-controlled authentication and protocol
  headers.
- RED 12 — the new resource/contract/version tests produced one missing-method error and six
  failures: authenticated raw/inbound/suppression/webhook operations were absent, the bundled
  contract and User-Agent were stale, byte comparison rejected equivalent YAML, and the scheduled
  workflow still depended on the private monorepo URL.
- GREEN 12 — the focused suite passed with 29 tests and 132 assertions after adding binary/CSV
  transport, the missing authenticated resources, semantic YAML normalization, the public contract
  endpoint, and the `0.2.0` User-Agent. The full suite then passed with 48 tests and 169 assertions.
- RED 13 — focused contract/security tests exposed permissive webhook and suppression payloads,
  unsafe webhook destinations, secret-bearing array responses and API exceptions, a shared raw/JSON
  response limit, pre-attestation release publication, and weak contract-download redirect bounds.
- GREEN 13 — the focused tests passed after strict local validation, a redacted
  `WebhookSecretResponse`, separate 40 MiB raw limits, recursive error redaction, HTTPS-only bounded
  contract downloads, and draft release publication gated by build provenance attestation. The
  complete suite passed with 55 tests and 241 assertions.
- RED 14 — the synchronized public snapshot exposed seven Laravel SDK operations without an
  idiomatic resource method; focused HTTP resource tests failed at the missing accessors.
- GREEN 14 — the focused resource suite passed after adding contact CSV import, domain health and
  inbound reads, message timeline and cancellation, segment previews, and batch sends with their
  critical local payload bounds.
- RED 15 — the release-documentation guard found stale `0.2.x` support/install references after
  the SDK version advanced to `0.3.0`.
- GREEN 15 — the guard passed after aligning both README install commands, the beta/support policy,
  and the dated `0.3.0` changelog entry.

## Refactor and final verification / Refatoração e verificação final

After all behaviors were green, common path validation moved to `Resource`, HTTP concerns remained
centralized in `Client`, repeated endpoint prefixes were extracted inside the larger resources, and
the suite stayed green after each formatting/static-analysis pass.

- Local PHP 8.5 + Laravel 13 + Testbench 11 + PHPUnit 12: `composer check` passed with 70 tests
  and 296 assertions; Composer validation, Pint, PHPStan level 9, and the OpenAPI snapshot check
  also passed.
- `composer audit`: no security vulnerability advisories found.
- The current public and source OpenAPI serializations have different byte hashes but both pass the
  semantic verifier against the bundled snapshot; an intentional title change fails with
  `OpenAPI semantic contract drift detected`.
- Local coverage was unavailable because no coverage driver is installed. CI explicitly provisions
  Xdebug and runs the coverage command.

Composer 2.10 currently blocks resolution of Laravel 11 because the framework's latest 11.x release
has upstream advisories. Compatibility was therefore tested in isolation with Composer's
`--no-blocking` mode, followed by a visible audit failure (three advisories, including HIGH
`GHSA-5vg9-5847-vvmq`); the maintained Laravel 12/13 dependency sets retain normal security
blocking and auditing. Current Guzzle documentation confirms that `on_headers` runs before body
download and rejects on throw, while `progress` receives four byte counters. Installed-source
verification showed that Guzzle 7 ignores a progress callback's return value whereas Guzzle 8 can
honor it, so the SDK throws to provide the same early-abort behavior across both generations.

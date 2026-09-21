# Changelog

All notable changes follow [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and semantic
versioning.

## [Unreleased]

## [0.2.1] - 2026-09-21

### Changed

- Synchronize the exact bundled OpenAPI 3.1 contract with the currently published public API,
  including contacts import, tracking domains, audience segments, inbound configuration, and
  broadcast foundations. This release does not claim high-level resource methods that the SDK has
  not implemented yet.

## [0.2.0] - 2026-09-16

### Added

- Add inbound-message downloads, suppression management/import/export, and the complete authenticated webhook operation surface.
- Add an explicitly accessed, log-safe response object for one-time webhook secrets.

### Changed

- Refresh the bundled OpenAPI contract and compare scheduled snapshots semantically against the public documentation endpoint.
- Enforce the public webhook and suppression mutation constraints before network I/O and allow raw RFC 5322 downloads up to 40 MiB without increasing JSON/error limits.
- Publish releases only after verification and provenance attestation, using immutable assets on a draft release.

### Fixed

- Remove the scheduled contract check's dependency on the private monorepo.
- Redact API keys and echoed server secrets from typed API error context.

## [0.1.1] - 2026-09-11

### Fixed

- Make release asset attachment repository-explicit in checkout-free jobs.

## [0.1.0] - 2026-09-11

### Added

- Initial Laravel 11/12/13 SDK with service provider, singleton injection, facade, and publishable config.
- Resources for send, messages, domains, templates, webhooks, automations, and usage.
- Hardened HTTP transport, typed errors, safe retries, idempotency keys, and frozen OpenAPI snapshot.

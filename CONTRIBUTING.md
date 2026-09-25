# Contributing / Contribuindo

Use a branch, keep changes focused, and never commit credentials. New behavior must follow the
Red → Green → Refactor cycle documented in `docs/TDD.md`.

```bash
composer install
composer check
composer audit
```

Contract changes must begin in `ViaPost-io/base-code/docs/openapi/public.yaml`. After that contract
is reviewed, update `openapi.yaml`, its SHA-256 in the verifier/tests, and the source metadata in one
pull request. / Mudanças de contrato começam no `base-code`; atualize o snapshot e seus checksums
somente depois da revisão do contrato.

By contributing, you agree that your contribution is licensed under MIT.

As a reusable library, this repository intentionally does not commit `composer.lock`: consumers
must resolve their own compatible dependency graph. Release source is fixed by an immutable,
protected `v*` Git reference resolved to the source commit verified by the release workflow; GitHub
Actions attests the published archive to that commit. Tags are not currently required to be signed
or annotated. The convenience ZIP is not promised to be byte-for-byte reproducible because Composer
archive metadata can include filesystem timestamps; the protected Git reference and release
attestation are the distribution provenance. Dependency-resolution test results are also time-bound
to the package versions available when the workflow runs. / Como biblioteca reutilizável, este
repositório não versiona `composer.lock`; a referência Git `v*` protegida, o commit verificado e a
atestado de provenance do GitHub Actions são a evidência de distribuição. As tags não exigem hoje
assinatura nem anotação; o ZIP pode variar por metadados de timestamp e os testes dependem das
versões compatíveis disponíveis na data da execução.

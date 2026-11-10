# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

The official PHP SDK, `yesly/yesly-php` on Packagist. PHP >= 8.1, PHPUnit and PHPStan level 8. Usage is in `README.md`.

## Commands

```bash
composer install
composer test                                # phpunit
composer test -- --filter "<name>"           # one test
composer analyse                             # phpstan level 8 on src/
composer generate                            # regenerate from ../yesly-openapi
YESLY_API_KEY=ysk_test_… php scripts/smoke.php   # smoke test against the real API
```

Smoke tests run against staging with a `ysk_test_` key and `YESLY_BASE_URL=https://api.dev.yesly.dev/v1`; they create and clean up one object per resource.

## How it is built

The API-shaped part is generated from `yesly-openapi/openapi.yaml` by `yesly-openapi/sdk/generate.mjs` (`composer generate`): `src/Generated/` and `src/Service/*Service.php` except `AbstractService.php`. Never edit it by hand: change the spec in `yesly-openapi`, lint it, and regenerate with both repos checked out side by side. Everything else is hand-written: `Client.php`, `HttpClient/`, `Exception/`, `Page.php`, `Webhook.php`, `Version.php`. The hand-written client sends `Authorization: Bearer <key>` and `Yesly-Version`, retries with backoff honouring `Retry-After`, adds an automatic `Idempotency-Key` to writes, pages with `starting_after`, and verifies webhook signatures. The procedure for an API change across all SDKs is `yesly-openapi/docs/sdks.md`; package names must match `yesly-web/src/lib/sdks.ts`.

## Releasing

Bump `Version::VERSION` in `src/Version.php`, then push a tag `v<version>`; Packagist reads the tag.

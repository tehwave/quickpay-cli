# Quickpay CLI Agent Guidelines

## Project

This is a PHP 8.4+ command-line client for Quickpay, built with Laravel Zero 12.
It targets Quickpay API v10. Composer installs the source package and `quickpay`
launcher; GitHub releases additionally publish a generated standalone PHAR.

Use `php quickpay` when running from source. See `README.md` for the public
command reference and `skills/quickpay/SKILL.md` for the agent-facing usage
guide.

## Structure

- `app/Commands` — thin command adapters grouped by API, authentication,
  callbacks, and payments.
- `app/Callbacks` — callback replay/watch workflows and their delivery,
  resolution, signing, and input capabilities.
- `app/Console` — command bases plus CLI input, output, confirmation, and
  terminal behavior.
- `app/Credentials` — API-key values, resolution, persistence, and redaction.
- `app/Quickpay` — authenticated transport, pagination, payment operations, and
  raw API requests.
- `tests/Unit` — focused behavior mirrored by production capability.
- `tests/Integration` — filesystem and boundary integration.
- `tests/Feature` — commands and multi-class workflows, mirrored by capability.
- `tests/Architecture` — structural constraints.

Do not create generic `Support`, `Helpers`, or command-concern directories.

## Commands

```bash
composer install
composer test
composer analyse
composer format:test
composer check
composer verify
```

Run focused tests directly with Pest:

```bash
vendor/bin/pest tests/Feature/Commands/Payments/ListPaymentsCommandTest.php
vendor/bin/pest tests/Unit/Quickpay/Raw/RawApiPathTest.php
```

Use `composer format` to apply Pint formatting. `composer check` runs tests,
analysis, and formatting checks. `composer verify` adds coverage, package
validation, and the locked-dependency audit.

## Code conventions

- Follow existing typed PHP and Laravel Zero patterns. Prefer constructor
  injection and PHPDoc array shapes where PHPStan needs them.
- Keep commands focused on input, service invocation, and presentation. Put
  reusable workflows and invariants in the owning capability.
- Keep namespaces aligned with paths and mirror production capability paths in
  tests.
- Add or update Pest coverage for every behavior change. Feature tests use
  Laravel HTTP fakes and must not contact Quickpay.
- Test both outgoing requests and CLI results. For mutations, also test
  confirmation, request ordering, and no-request failure paths.
- Preserve raw API JSON in `--json` mode after sanitization. Keep
  machine-readable output on stdout and diagnostics or prompts on stderr.
- Restore environment variables and other global state changed by a test.
- Update `README.md`, command help, and `skills/quickpay/SKILL.md` when a command
  or safety behavior changes.
- Check current Quickpay documentation before changing API behavior; do not
  infer the remote contract solely from existing code.

## Security invariants

- Never expose an API key in output, errors, logs, fixtures, or command
  arguments. Preserve redaction of raw, Basic-auth, and encoded forms.
- Credentials come from a non-empty `QUICKPAY_API_KEY` before
  `~/.config/quickpay/config.json`. Stored credentials use atomic writes, a
  `0700` directory, and a `0600` file.
- The client owns the Quickpay host, authentication, API version, and JSON
  headers. Raw API input must not override them or escape the
  `api.quickpay.net` origin.
- Pagination follows only validated Quickpay HTTPS `rel="next"` links and
  remains bounded and cycle-safe.
- Do not retry mutations automatically. Capture, refund, cancel, and non-`GET`
  raw requests retain confirmation and non-interactive `--yes` safeguards.
- Amounts are integer minor units. For example, `1000` DKK means DKK 10.00.

Treat changes to credentials, redaction, raw paths or headers, pagination,
confirmations, response validation, callback delivery, or stdout/stderr routing
as security-sensitive. Include hostile and malformed inputs in their tests.

## Packaging

`builds/` is ignored generated output. Do not commit a PHAR. After packaged
runtime code, configuration, Composer inputs, the launcher, or `box.json`
changes, run:

```bash
composer build
builds/quickpay --version
builds/quickpay list --raw
```

Use `composer release:build -- 1.0.0` only for a versioned release candidate.
Follow `RELEASING.md`; the GitHub workflow publishes artifacts without writing
generated binaries back to the source branch.

## Verification

Before finishing, run focused tests, `composer check`, the applicable packaging
check, and `git diff --check`. Run `composer verify` for completion-level
verification. Review the complete diff and report any checks not run.

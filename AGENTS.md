# Quickpay CLI Agent Guidelines

## Project

This is a PHP 8.4+ command-line client for Quickpay, built with Laravel Zero 12. It targets Quickpay API v10 and ships as a tracked PHAR in `builds/quickpay`.

Use `php quickpay` when running the application from source. Use Composer 2.8.9 for reproducible PHAR work. See `README.md` for the public command reference and `skills/quickpay/SKILL.md` for the agent-facing usage guide.

## Structure

- `app/Commands` — Laravel Zero commands and CLI presentation.
- `app/Quickpay` — HTTP client and response handling.
- `app/Credentials` — credential storage and redaction.
- `app/Support` — input parsing, validation, pagination, and output sanitization.
- `tests/Feature` — command and HTTP integration tests.
- `tests/Unit` — focused tests for support classes.
- `scripts/verify-phar-source.php` — verifies the complete PHAR against the checkout.

## Commands

```bash
composer install
composer test
composer analyse
composer format:test
composer check
```

Run a focused test directly with Pest:

```bash
vendor/bin/pest tests/Feature/PaymentsListCommandTest.php
vendor/bin/pest tests/Unit/RawApiPathTest.php
```

Use `composer format` to apply Pint formatting. `composer check` is the full local quality gate.

## Code conventions

- Follow the existing PHP style and Laravel Zero patterns. Use typed parameters and return values, constructor injection, and PHPDoc array shapes where needed by PHPStan.
- Keep commands focused on input and presentation. Put reusable HTTP, credential, parsing, validation, and sanitization logic in the existing domain classes.
- Add or update Pest coverage for every behavior change. Feature tests use Laravel HTTP fakes and must not contact Quickpay.
- Test both the outgoing request and the CLI result. For mutations, also test confirmation, request ordering, and no-request failure paths.
- Preserve raw API JSON in `--json` mode after sanitization. Keep machine-readable output on stdout and diagnostics or prompts on stderr.
- Restore environment variables and other global state changed by a test.
- Update `README.md`, command help, and `skills/quickpay/SKILL.md` when a command or its safety behavior changes.
- Check the current Quickpay documentation before changing API behavior; do not infer the remote contract from existing code alone.

## Security invariants

- Never expose an API key in output, errors, logs, fixtures, or command arguments. Preserve redaction of raw, Basic-auth, and encoded forms.
- Credentials come from a non-empty `QUICKPAY_API_KEY` before `~/.config/quickpay/config.json`. Stored credentials use atomic writes, a `0700` directory, and a `0600` file.
- The client owns the Quickpay host, authentication, API version, and JSON headers. Raw API input must not override them or escape the `api.quickpay.net` origin.
- Pagination follows only validated Quickpay HTTPS `rel="next"` links and remains bounded and cycle-safe.
- Do not retry mutations automatically. Capture, refund, cancel, and non-`GET` raw requests retain their confirmation and non-interactive `--yes` safeguards.
- Amounts are integer minor units. For example, `1000` DKK means DKK 10.00.

Treat changes to credentials, redaction, raw paths or headers, pagination, confirmations, response validation, or stdout/stderr routing as security-sensitive. Include hostile and malformed inputs in their tests.

## PHAR

`builds/quickpay` is committed and must match the packaged source. Do not edit it directly. After changing packaged runtime code, configuration, Composer inputs, the launcher, or `box.json`, run:

```bash
composer build
builds/quickpay --version
builds/quickpay list --raw
php scripts/verify-phar-source.php builds/quickpay dev
composer validate --strict
```

Documentation- and test-only changes do not require a PHAR rebuild. Follow the versioned release procedure in `README.md` for non-development builds.

## Verification

Before finishing, run the focused tests for the change, `composer check`, and `git diff --check`. Review the complete diff and report any checks that were not run.

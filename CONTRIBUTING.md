# Contributing

Thank you for helping improve Quickpay CLI. Payment software has a small margin for ambiguity, so changes should stay focused, observable, and explicit about safety trade-offs.

## Local setup

Quickpay CLI requires PHP 8.4 or newer and Composer 2.10.2 for reproducible PHAR work.

```bash
composer install
php quickpay --version
composer check
```

The test suite uses Laravel HTTP fakes. Tests must not contact Quickpay or require a real API key.

## Making changes

- Keep commands focused on input and presentation.
- Put reusable HTTP, credential, parsing, validation, pagination, and output-sanitization behavior in the existing domain classes.
- Add or update Pest coverage for every behavior change.
- Test outgoing requests, stdout/stderr routing, exit codes, prompts, failure paths, and absence of requests after rejected input.
- Preserve original API JSON in `--json` mode unless sanitization is required.
- Use integer minor units for amounts.
- Update the README, command help, and `skills/quickpay/SKILL.md` when the CLI contract or safety workflow changes.

Comments should explain why a constraint exists, which threat or trade-off it addresses, or why an apparently simpler implementation is unsafe. Avoid comments that restate the code.

## Security-sensitive changes

Treat credentials, redaction, raw paths or headers, pagination, confirmations, response validation, and stdout/stderr routing as security-sensitive.

Include hostile, malformed, mixed-case, encoded, and non-interactive inputs in tests. Never place a real API key in fixtures, command arguments, logs, screenshots, issues, or pull requests.

The client owns the Quickpay host, authentication, API version, and JSON headers. Mutations must not be retried automatically, and unattended mutations must retain their `--yes` safeguard.

## Verification

Run focused tests while developing, then:

```bash
composer check
composer coverage
composer audit --locked
composer validate --strict
git diff --check
```

`composer coverage` requires PCOV or Xdebug and enforces at least 90% application coverage.

When packaged runtime code, configuration, Composer inputs, the launcher, or `box.json` changes, rebuild and verify the tracked PHAR:

```bash
composer build
builds/quickpay --version
builds/quickpay list --raw
php scripts/verify-phar-source.php builds/quickpay dev
```

Documentation- and test-only changes do not require a PHAR rebuild.

## Pull requests

Keep a pull request limited to one coherent change. Explain:

- what user-visible behavior changed;
- why the change is necessary;
- which safety boundaries were considered;
- which exact checks were run;
- whether the PHAR was rebuilt.

Do not combine unrelated cleanup with security-sensitive behavior.

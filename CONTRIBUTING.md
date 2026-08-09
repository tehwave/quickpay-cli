# Contributing

Thank you for helping improve Quickpay CLI. Payment software has a small margin
for ambiguity, so changes should stay focused, observable, and explicit about
safety trade-offs.

## Local setup

Quickpay CLI requires PHP 8.4 or newer.

```bash
composer install
php quickpay --version
composer check
```

The test suite uses Laravel HTTP fakes. Tests must not contact Quickpay or
require a real API key.

## Architecture

Production code is grouped by capability:

- `app/Commands` contains thin Laravel Zero adapters, grouped by public command
  family.
- `app/Callbacks` owns callback input, signing, delivery, resolution, and
  watching workflows.
- `app/Console` owns shared command bases plus CLI input, output, confirmation,
  and terminal behavior.
- `app/Credentials` owns credential values, resolution, persistence, and
  redaction.
- `app/Quickpay` owns authenticated transport, pagination, payment operations,
  and raw API requests.

Do not introduce generic `Support`, `Helpers`, or command-trait grab bags.
Place a class with the capability whose language and invariants it implements.
Commands should translate arguments and options, invoke an application service,
and render its result; reusable workflows do not belong in command classes.

Tests mirror production capability paths beneath `tests/Unit`,
`tests/Integration`, and `tests/Feature`. Architecture tests enforce namespace
alignment and the main structural boundaries.

## Making changes

- Follow the existing typed PHP and Laravel Zero conventions.
- Add or update Pest coverage for every behavior change.
- Test outgoing requests, stdout/stderr routing, exit codes, prompts, failure
  paths, and absence of requests after rejected input.
- Preserve original API JSON in `--json` mode unless credential sanitization is
  required.
- Use integer minor units for amounts.
- Update the README, command help, and `skills/quickpay/SKILL.md` when the CLI
  contract or safety workflow changes.
- Check current Quickpay documentation before changing API behavior; do not
  infer the remote contract solely from existing code.

Comments should explain why a constraint exists, which threat or trade-off it
addresses, or why an apparently simpler implementation is unsafe. Avoid
comments that restate the code.

## Security-sensitive changes

Treat credentials, redaction, raw paths or headers, pagination, confirmations,
response validation, callback delivery, and stdout/stderr routing as
security-sensitive.

Include hostile, malformed, mixed-case, encoded, and non-interactive inputs in
tests. Never place a real API key in fixtures, command arguments, logs,
screenshots, issues, or pull requests.

The client owns the Quickpay host, authentication, API version, and JSON
headers. Mutations must not be retried automatically, and unattended mutations
must retain their `--yes` safeguard.

## Verification

Run focused tests while developing, then run:

```bash
composer check
composer verify
git diff --check
```

`composer check` runs the full test suite, PHPStan, and Pint. `composer verify`
also enforces at least 90% application coverage, validates package metadata,
and audits the locked dependencies. Coverage requires PCOV or Xdebug.

If packaging behavior changes, also exercise the ignored development artifact:

```bash
composer build
builds/quickpay --version
builds/quickpay list --raw
```

Generated files beneath `builds/` are never committed. The release workflow
builds the versioned PHAR from a tagged source commit.

## Pull requests

Keep a pull request limited to one coherent change. Explain:

- what user-visible behavior changed;
- why the change is necessary;
- which safety boundaries were considered;
- which exact checks were run;
- whether packaging behavior was exercised.

Do not combine unrelated cleanup with security-sensitive behavior.

# Changelog

All notable changes to Quickpay CLI are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Named merchants. `merchants:add`, `merchants:list`, `merchants:select`, `merchants:active` and `merchants:remove` save and choose between several Quickpay merchants, and every authenticated command takes `--merchant`.
- Project merchant bindings. `merchants:select <name> --local` writes `.quickpay-cli.json`, so commands in that folder and below use that merchant.
- Read and write access flags for each saved merchant, set with `merchants:add --no-read` or `--no-write` and changed with `merchants:access`. Blocked requests fail before anything is sent.
- Payment mutations, payment creation, raw mutations and callback watching name the merchant they use.
- `callbacks:watch` and `callbacks:replay` warn when `QUICKPAY_PRIVATE_KEY` is set while a saved merchant is selected.

### Changed

- The bundled agent skill is renamed from `quickpay` to `quickpay-cli`.

### Removed

- **Breaking:** the `login`, `logout` and `auth` commands. Save a merchant with `quickpay merchants:add <name>`, and use `merchants:active --check` instead of `auth --check`.
- **Breaking:** the single-key configuration in `~/.config/quickpay/config.json` is no longer read. Add your merchant again with `merchants:add`, then delete the old file. `merchants:add` reminds you while it still exists.

## [0.2.0] - 2026-08-10

### Fixed

- Packagist releases now install the bundled PHAR without Laravel Zero's dependency graph, using a single Quality-gated release build, signed tag, and manually published immutable GitHub release.

### Removed

- Automated GitHub release publication, custom release scripts, and the separate development PHAR. Releases now use Laravel Zero's native build command, the `Quality` workflow, and one committed `builds/quickpay` binary.

## [0.1.0] - 2026-08-09

### Added

- Merchant API-key login, authentication status, and logout commands.
- Payment create, list, inspect, and payment-link commands.
- Confirmed capture, refund, and cancel workflows.
- Guarded raw Quickpay API access.
- Human-readable output and sanitized machine-readable JSON.
- Bounded, origin-validated, cycle-safe pagination.
- Signed callback replay and foreground callback watching for local development.
- Atomic credential storage with restrictive filesystem permissions.
- Generated PHAR release workflow with checksums and provenance attestations.
- PHP 8.4 and 8.5 CI, PHPStan level 8, Pint, dependency audit, and a 90% coverage gate.

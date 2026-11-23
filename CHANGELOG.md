# Changelog

All notable changes to Quickpay CLI are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.0] - 2026-11-23

### Added

- Named merchants. `merchants:add`, `merchants:list`, `merchants:select`, `merchants:active` and `merchants:remove` save and choose between several Quickpay merchants, and every authenticated command takes `--merchant`.
- Project merchant bindings. `merchants:select <name> --local` writes `.quickpay-cli.json`, so commands in that folder and below use that merchant.
- Read-only merchants. `merchants:add --read-only` or `merchants:access --read-only` blocks every request except `GET` before anything is sent, and `merchants:access --read-write` allows writes again after a confirmation.
- Payment mutations, payment creation, raw mutations and callback watching name the merchant they use.
- `callbacks:watch` and `callbacks:replay` warn when `QUICKPAY_PRIVATE_KEY` is set while a saved merchant is selected.

### Changed

- The PHAR no longer bundles the PHPStan and Larastan development tools, so it is about half the size of 0.2.0's.
- The bundled agent skill is renamed from `quickpay` to `quickpay-cli`. Remove the old `quickpay` skill folder from your agent's skills directory, then run `skills add tehwave/quickpay-cli`.

### Removed

- **Breaking:** the `login`, `logout` and `auth` commands. Save a merchant with `quickpay merchants:add <name>`, and use `merchants:active --check` instead of `auth --check`.
- **Breaking:** the single-key configuration in `~/.config/quickpay/config.json` is no longer read. Add your merchant again with `merchants:add`, then delete the old file. `merchants:add` reminds you while it still exists.

### Security

- The bundled Symfony HTTP Foundation, HTTP Kernel and Mime packages are updated to releases that fix published security advisories.

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

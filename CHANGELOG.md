# Changelog

All notable changes to Quickpay CLI are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Merchant API-key login, authentication status, and logout commands.
- Payment create, list, inspect, and payment-link commands.
- Confirmed capture, refund, and cancel workflows.
- Guarded raw Quickpay API access.
- Human-readable output and sanitized machine-readable JSON.
- Bounded, origin-validated, cycle-safe pagination.
- Atomic credential storage with restrictive filesystem permissions.
- Tracked PHAR distribution with complete source-integrity verification.
- PHP 8.4 and 8.5 CI, PHPStan level 8, Pint, dependency audit, and a 90% coverage gate.

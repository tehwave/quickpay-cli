# Quickpay CLI

[![Quality](https://github.com/tehwave/quickpay-cli/actions/workflows/quality.yml/badge.svg)](https://github.com/tehwave/quickpay-cli/actions/workflows/quality.yml)
[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

**Manage Quickpay payments and test callbacks from your terminal.**

Create payment links, manage payments through the [Quickpay API](https://learn.quickpay.net/tech-talk/api/), use JSON output in scripts or for agents, and receive signed callbacks on localhost without a tunnel.

## Features

- 🔁 Watch Quickpay payment activity and relay signed callbacks to localhost. No tunnel or public development server required.
- Create payments and hosted payment links.
- List, filter, and inspect payments and their operations.
- Capture, refund, and cancel payments.
- Call any Quickpay API v10 endpoint.
- Script- and agent-friendly, with JSON output and non-interactive options.

⭐ If you like Quickpay CLI, [star it on GitHub](https://github.com/tehwave/quickpay-cli). It helps a lot!

## Installation

Quickpay CLI requires PHP 8.4 or newer.

```bash
composer global require tehwave/quickpay-cli
```

Install the bundled skill for coding agents:

```bash
skills add tehwave/quickpay-cli
```

Authenticate with a Quickpay merchant API key:

```bash
quickpay login
```

For automation, supply the API key through `QUICKPAY_API_KEY`.

## Usage

```bash
quickpay list
```

Use `--help` to explore any command and its options:

```bash
quickpay callbacks:watch --help
```

### Watch for callbacks and relay them

```bash
quickpay callbacks:watch --to=http://127.0.0.1:8000/quickpay/callback
```

Leave the watcher running while testing the payment flow. It signs and sends
payment updates to your local callback handler.

With no selector, the watcher forwards new operation callbacks for every
payment changed after it becomes ready. Pass a payment ID or `--order-id` to
narrow the watch to one payment. Existing operations are not replayed.

## Development

```bash
composer install
composer check
composer verify
```

`composer check` runs tests, static analysis, and formatting checks. `composer
verify` additionally enforces coverage, validates package metadata, and audits
the locked dependencies. The test suite uses HTTP fakes and does not contact
Quickpay API.

Build an ignored development PHAR when packaging behavior changes:

```bash
composer build
builds/quickpay --version
builds/quickpay list --raw
```

The PHAR is a generated release artifact, not repository source. Versioned
release artifacts are built with `composer release:build -- 1.0.0`.

See [CONTRIBUTING.md](CONTRIBUTING.md) before changing commands or security behavior.

Maintainers should follow [RELEASING.md](RELEASING.md) for versioned releases.

## Security

Quickpay CLI keeps API keys out of command arguments and asks for confirmation before changing a payment.

See [SECURITY.md](SECURITY.md) to report a vulnerability. Never include credentials or merchant data in an issue.

## Credits

Created and maintained by [Peter 🌊 Jørgensen](https://peterchrjoergensen.dk).

Built with [Laravel Zero](https://laravel-zero.com/) and released under the [MIT License](LICENSE.md).

Quickpay CLI is an independent open-source project. It is not affiliated with or endorsed by Quickpay.

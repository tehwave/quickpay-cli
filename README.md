# Quickpay CLI

[![Quality](https://github.com/tehwave/quickpay-cli/actions/workflows/quality.yml/badge.svg)](https://github.com/tehwave/quickpay-cli/actions/workflows/quality.yml)
[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

**Quickpay from your terminal, including callbacks on localhost.**

Quickpay CLI is a focused client for creating, inspecting, and managing
payments through the [Quickpay API](https://learn.quickpay.net/tech-talk/api/).
Use it interactively with readable output or in automation with JSON.

## Features

- 🔁 Watch Quickpay payment activity and relay signed callbacks to a local endpoint, or replay the current payment state on demand.
- Create payments and hosted payment links.
- List, filter, and inspect payments and their operations.
- Capture, refund, and cancel payments with confirmation safeguards.
- Access Quickpay API v10 endpoints without a dedicated command.
- Produce machine-readable JSON for scripts and automation.

## Installation

Quickpay CLI requires PHP 8.4 or newer.

```bash
composer global require tehwave/quickpay-cli
```

Authenticate with a Quickpay merchant API key:

```bash
quickpay login
```

For automation, supply the API key through `QUICKPAY_API_KEY`.

## Usage

```bash
quickpay payments:create order1234
quickpay payments:list --accepted
quickpay payments:get 884201 --json
quickpay payments:link 884201 2500
quickpay callbacks:watch --order-id=order1234 \
  --to=http://127.0.0.1:8000/quickpay/callback
```

Amounts use integer minor units, so `1000` DKK means DKK 10.00. Commands that
change a payment ask for confirmation.

Use `--help` to explore any command and its options:

```bash
quickpay list
quickpay payments:link --help
quickpay callbacks:watch --help
```

## Development

```bash
composer install
composer check
```

See [CONTRIBUTING.md](CONTRIBUTING.md) before changing commands or security
behavior. Maintainers should follow [RELEASING.md](RELEASING.md) for releases.

## Security

Quickpay CLI keeps API keys out of command arguments and adds confirmation
around payment-changing commands. Read [SECURITY.md](SECURITY.md) before
reporting a vulnerability, and never include credentials or merchant data in
an issue.

## Credits

Created and maintained by [Peter Chr. Jørgensen](https://peterchrjoergensen.dk).
Built with [Laravel Zero](https://laravel-zero.com/) and released under the
[MIT License](LICENSE.md).

Quickpay CLI is an independent open-source project. It is not affiliated with
or endorsed by Quickpay.

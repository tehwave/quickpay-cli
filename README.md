# Quickpay CLI

[![Quality](https://github.com/tehwave/quickpay-cli/actions/workflows/quality.yml/badge.svg)](https://github.com/tehwave/quickpay-cli/actions/workflows/quality.yml)
[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

A focused command-line client for the [Quickpay API](https://learn.quickpay.net/tech-talk/api/). Create and inspect payments, generate payment links, capture, refund, cancel, automate with JSON, and work with callbacks from a local development environment.

This is an independent open-source project. It is not affiliated with or endorsed by Quickpay.

## Develop Quickpay callbacks on localhost

Quickpay callbacks normally need a publicly reachable URL. `callbacks:watch`
brings them into a private local environment: it watches payment activity
through the Quickpay API, rebuilds the signed callback, and relays it straight
to your local endpoint.

Start it with an order ID before stepping through a checkout flow:

```bash
quickpay callbacks:watch --order-id=demo123 \
  --to=http://127.0.0.1:8000/quickpay/callback
```

The watcher waits for the payment to appear and then forwards callbacks as new
operations happen. Your application can run its real callback verification and
handling code without a tunnel, a public development server, or copied
payloads.

Use `callbacks:replay` when you only need to send the current payment state
once.

## What it can do

- Create payments and hosted payment links.
- List, filter, and inspect payments and their operations.
- Capture, refund, and cancel payments with confirmation safeguards.
- Watch payment activity and replay signed callbacks to local applications.
- Access Quickpay API v10 endpoints that do not have a dedicated command.
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

For automation, the API key can instead be supplied through
`QUICKPAY_API_KEY`.

## Quick start

```bash
quickpay payments:list --accepted
quickpay payments:get 884201 --json
quickpay payments:link 884201 2500
```

Amounts use integer minor units, so `1000` DKK means DKK 10.00. Commands that
change a payment ask for confirmation; use `--yes` only for intentionally
authorized automation.

Every command documents its arguments and options:

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

## For agents

Install the bundled Quickpay CLI skill for coding agents:

```bash
skills add tehwave/quickpay-cli
```

## Security

Please read [SECURITY.md](SECURITY.md) before reporting a vulnerability. Never
include API keys, authorization headers, real payment payloads, or merchant
data in an issue.

## License

Quickpay CLI is open-source software licensed under the [MIT License](LICENSE.md).

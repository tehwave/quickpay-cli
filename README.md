# Quickpay CLI

[![Quality](https://github.com/tehwave/quickpay-cli/actions/workflows/quality.yml/badge.svg)](https://github.com/tehwave/quickpay-cli/actions/workflows/quality.yml)
[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

**Manage Quickpay payments and test callbacks from your terminal.**

Create payment links, manage payments through the
[Quickpay API](https://learn.quickpay.net/tech-talk/api/), use JSON output in
scripts, and receive signed callbacks on localhost without a tunnel.

## Features

- 🔁 Watch Quickpay payment activity and relay signed callbacks to localhost. No tunnel or public development server required.
- Create payments and hosted payment links.
- List, filter, and inspect payments and their operations.
- Capture, refund, and cancel payments.
- Call any Quickpay API v10 endpoint.
- Output JSON for scripts.

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
quickpay login

quickpay callbacks:watch --order-id=order1234 \
  --to=http://127.0.0.1:8000/quickpay/callback
```

Leave the watcher running while testing the payment flow. It signs and sends
payment updates to your local callback handler.

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

Quickpay CLI keeps API keys out of command arguments and asks for confirmation
before changing a payment. See [SECURITY.md](SECURITY.md) to report a
vulnerability. Never include credentials or merchant data in an issue.

## Credits

Created and maintained by [Peter Chr. Jørgensen](https://peterchrjoergensen.dk).
Built with [Laravel Zero](https://laravel-zero.com/) and released under the
[MIT License](LICENSE.md).

Quickpay CLI is an independent open-source project. It is not affiliated with
or endorsed by Quickpay.

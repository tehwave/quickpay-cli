# Quickpay CLI

[![Quality](https://github.com/tehwave/quickpay-cli/actions/workflows/quality.yml/badge.svg)](https://github.com/tehwave/quickpay-cli/actions/workflows/quality.yml)
[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

**Manage Quickpay payments from the terminal—and test callbacks on localhost.**

Create payment links, inspect and manage payments through the
[Quickpay API](https://learn.quickpay.net/tech-talk/api/), automate workflows
with JSON, and run your local callback handler against real Quickpay payment
activity without exposing it to the internet.

## Features

- 🔁 Watch Quickpay payment activity and relay signed callbacks to localhost—no tunnel or public development server required.
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
quickpay login

quickpay callbacks:watch --order-id=order1234 \
  --to=http://127.0.0.1:8000/quickpay/callback
```

Continue through the payment flow as usual. Signed callbacks arrive at your
local handler as payment operations happen.

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

![](https://banners.beyondco.de/Quickpay%20CLI.png?theme=light&packageManager=composer+require&packageName=tehwave%2Fquickpay-cli&pattern=wiggle&style=style_1&description=Manage+Quickpay+payments+and+test+callbacks+from+your+terminal.&md=1&showWatermark=1&fontSize=100px&images=terminal)

# Quickpay CLI

[![Quality](https://github.com/tehwave/quickpay-cli/actions/workflows/quality.yml/badge.svg)](https://github.com/tehwave/quickpay-cli/actions/workflows/quality.yml)
[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

Manage Quickpay payments, create payment links, and relay signed callbacks to localhost without a tunnel.

## Features

- 🔁 Watch Quickpay payment activity and relay signed callbacks to localhost. No tunnel or public development server required.
- Create payments and hosted payment links.
- List, filter, and inspect payments and their operations.
- Capture, refund, and cancel payments.
- Call any Quickpay API v10 endpoint.
- Script- and agent-friendly, with JSON output and non-interactive options.

⭐ If you like Quickpay CLI, [star it on GitHub](https://github.com/tehwave/quickpay-cli). It helps a lot!

## Install

Requires PHP 8.4 or newer.

```bash
composer global require tehwave/quickpay-cli
```

## Authentication

Sign in to [Quickpay Manager](https://manage.quickpay.net/) and select your merchant
account. Open [Settings > Integration](https://quickpay.net/helpdesk/integration-setup/)
and copy the API key under **API User**. Then run:

```bash
quickpay merchants:add "My Website"
```

Paste the key when prompted. Your input stays hidden, and the CLI saves the key in
`~/.config/quickpay-cli/config.json`. The first merchant becomes your default.

## Merchants

```bash
quickpay merchants:add "My Other Website"
quickpay merchants:select "My Other Website"
quickpay merchants:active --check
quickpay payments:list --merchant="My Website"
```

To use a different merchant for a project, run
`quickpay merchants:select "My Website" --local` from the project folder. Commands in
that folder and its subfolders will use **My Website** instead of your default merchant.
You can still choose another merchant for a single command with `--merchant`.

For scripts and CI, you can supply the API key through `QUICKPAY_API_KEY`
instead of saving a merchant. Use it outside folders with a project merchant
selected, and leave out `--merchant`.

## Payments

```bash
quickpay payments:list
quickpay payments:get <payment-id> --json
quickpay payments:link <payment-id> 1000
```

Amounts are in minor units: `1000` is DKK 10.00 for a DKK payment.
Capture, refund, cancel, and non-GET raw API requests require confirmation
or `--yes` when running unattended.

Run `quickpay list` for all commands or `quickpay <command> --help` for options.

## Local callbacks

```bash
quickpay callbacks:watch --to=http://127.0.0.1:8000/quickpay/callback
```

Leave it running while testing your payment flow. It forwards new operations
for all payments in the selected merchant account. Add a payment ID to watch
one payment. Existing operations are not replayed.

To send a payment's current resource once:

```bash
quickpay callbacks:replay <payment-id> --to=http://127.0.0.1:8000/quickpay/callback
```

## For agents

Install the bundled [quickpay-cli skill](skills/quickpay-cli/SKILL.md):

```bash
skills add tehwave/quickpay-cli
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md), [RELEASING.md](RELEASING.md), and
[SECURITY.md](SECURITY.md).

Created by [Peter Jørgensen](https://peterchrjoergensen.dk). Built with
[Laravel Zero](https://laravel-zero.com/) and released under the [MIT License](LICENSE.md).
Independent of and not endorsed by Quickpay.

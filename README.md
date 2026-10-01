![](https://banners.beyondco.de/Quickpay%20CLI.png?theme=light&packageManager=composer+require&packageName=tehwave%2Fquickpay-cli&pattern=wiggle&style=style_1&description=Manage+Quickpay+payments+and+test+callbacks+from+your+terminal.&md=1&showWatermark=1&fontSize=100px&images=terminal)

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

Install the bundled [quickpay-cli skill](skills/quickpay-cli/SKILL.md) for coding agents:

```bash
skills add tehwave/quickpay-cli
```

If you previously installed this project's skill as `quickpay`, remove that
installed copy and run the installation command again. Check its origin before
removal so an unrelated Quickpay skill stays intact.

Save access to each merchant once. The CLI privately prompts for its API key:

```bash
quickpay merchants:add shop-a
```

Credentials are stored in `~/.config/quickpay-cli/config.json` with a `0700`
directory and `0600` file. Merchant names are local labels you choose, not verified
Quickpay account names or merchant IDs. Names contain 1-64 lowercase ASCII
letters, digits or single separating hyphens, starting with a letter.

For automation, supply the API key through `QUICKPAY_API_KEY`. It cannot be
combined with `--merchant` or a project binding; unset it before selecting a
saved merchant.

## Usage

```bash
quickpay list
```

Use `--help` to explore any command and its options:

```bash
quickpay callbacks:watch --help
```

### Select a merchant

```bash
quickpay merchants:add shop-b
quickpay merchants:list
quickpay merchants:select shop-a
quickpay merchants:select                 # Interactive picker
quickpay merchants:active                 # Local configuration, no API request
quickpay merchants:active --check --json  # Verify API access for automation
quickpay payments:list --merchant=shop-b
```

The first saved merchant becomes the global default. Adding another merchant
keeps that default. Select a different default with `merchants:select`, or use
`--merchant` for one payment, callback or raw API command.

Bind the current project without changing the global default:

```bash
quickpay merchants:select shop-b --local
```

Run this from the project root. It writes `.quickpay-cli.json` containing only
`{"merchant":"shop-b"}`. Commands search the current directory and its parents
for the nearest binding. Ignore this file in Git when the selection is specific
to one developer. The selection order is an explicit `--merchant`, then the
nearest project binding, then the global default. Unknown names and invalid
bindings fail without silently switching to another merchant.

Each invocation resolves its merchant once. A running callback watcher stays
attached to that merchant when another command changes selection or removes the
saved credential. Mutation confirmations and watcher startup show the saved
label and credential source; payment confirmations also show the API's merchant
ID when available. These messages go to stderr in JSON mode.

`merchants:active` reports configuration. Add `--check` to verify the key against
Quickpay; a missing or rejected credential exits non-zero. `--json` keeps the
status machine-readable, with diagnostics on stderr.

Rotate a saved key with `quickpay merchants:add shop-a --replace`. The old key
remains intact if validation fails. `quickpay merchants:remove shop-a` removes
only local access, never the remote account. Removing the default leaves no
default; it does not select another saved merchant. Any binding to a removed
merchant will fail until you select a valid one.

This pre-v1 change replaces `login`, `logout` and `auth`. The old
`~/.config/quickpay/config.json` is not read or migrated. Add each merchant again
with `merchants:add`; after setup, you may remove the old file yourself.

### Watch for callbacks and relay them

```bash
quickpay callbacks:watch --to=http://127.0.0.1:8000/quickpay/callback
```

Leave the watcher running while testing the payment flow. It signs and sends
payment updates to your local callback handler.

With no selector, the watcher forwards new operation callbacks for every
payment changed after it becomes ready. Pass a payment ID or `--order-id` to
narrow the watch to one payment. Existing operations are not replayed. The
watcher runs in the foreground and has no JSON mode.

An explicit `QUICKPAY_PRIVATE_KEY` must belong to the selected merchant. Without
that override, the CLI fetches the signing key through the same merchant's API
access and keeps it only in memory.


```bash
quickpay callbacks:replay <payment-id> --to=<corrected-url>
```

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

The versioned `builds/quickpay` PHAR is tracked so Packagist can install it
without Laravel Zero's dependency graph. Maintainers build it with Laravel
Zero's `app:build` command and commit it as described in `RELEASING.md`.

See [CONTRIBUTING.md](CONTRIBUTING.md) before changing commands or security behavior.

Maintainers should follow [RELEASING.md](RELEASING.md) for versioned releases.

## Security

Quickpay CLI keeps API keys out of command arguments and asks for confirmation before changing a payment.

See [SECURITY.md](SECURITY.md) to report a vulnerability. Never include credentials or merchant data in an issue.

## Credits

Created and maintained by [Peter 🌊 Jørgensen](https://peterchrjoergensen.dk).

Built with [Laravel Zero](https://laravel-zero.com/) and released under the [MIT License](LICENSE.md).

Quickpay CLI is an independent open-source project. It is not affiliated with or endorsed by Quickpay.

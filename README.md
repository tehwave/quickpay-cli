# Quickpay CLI

[![Quality](https://github.com/tehwave/quickpay-cli/actions/workflows/quality.yml/badge.svg)](https://github.com/tehwave/quickpay-cli/actions/workflows/quality.yml)
[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

A command-line client for the [Quickpay API](https://learn.quickpay.net/tech-talk/api/).

Quickpay CLI gives merchant operators and automation a small, inspectable interface for creating payment links, reading payment state, replaying signed callbacks to local development environments, and carrying out explicitly authorized captures, refunds, and cancellations. It preserves machine-readable JSON on stdout, keeps diagnostics on stderr, and puts guardrails around credentials, raw API access, pagination, and payment mutations.

This is an independent open-source project. It is not affiliated with or endorsed by Quickpay.

## Installation

Quickpay CLI requires PHP 8.4 or newer. Install it globally with Composer:

```bash
composer global require tehwave/quickpay-cli
```

```bash
quickpay --version
```

## Quick start

Authenticate with a Quickpay merchant API key:

```bash
quickpay login
```

`login` reads the key through a hidden interactive prompt, validates merchant scope, and stores it in `~/.config/quickpay/config.json`.

```bash
quickpay auth
```

Then inspect payments:

```bash
quickpay payments:list --accepted
```

```bash
quickpay payments:get 884201 --json
```

Use `--json` for scripts and pipelines. Successful JSON stays on stdout; safety messages, prompts, and errors use stderr where necessary.

## Model

- Amounts are integer minor units. `1000` DKK means DKK 10.00.
- `QUICKPAY_API_KEY` takes precedence over the stored config when it is non-empty.
- API keys are never accepted as command arguments or custom authorization headers.
- `payments:create` and `payments:link` create remote resources; run them only with clear operator intent.
- Capture, refund, cancel, and non-`GET` raw requests show a summary and require confirmation.
- Non-interactive mutations require `--yes`. The flag bypasses the prompt; it does not replace human authorization.
- Payment mutations fetch current payment context before confirmation.
- Mutations are never retried automatically.
- Pagination follows only bounded, cycle-safe links on the exact Quickpay HTTPS origin.
- Callback replay/watch signs the sanitized bytes it forwards and never sends Quickpay API authentication to the destination.

Before a capture, refund, or cancellation, inspect the payment and verify its ID, order, currency, state, accepted status, balance, and requested amount:

```bash
quickpay payments:get 884201 --json
```

```bash
quickpay payments:capture 884201 2500 --yes --json
```

## Commands

### Authentication

```bash
quickpay login
```

```bash
quickpay auth
```

```bash
quickpay logout
```

`logout` removes only the stored config file. When `QUICKPAY_API_KEY` is set, the environment credential remains active until it is unset.

### Create and inspect payments

```text
quickpay payments:create <order-id> [currency=DKK]
  [--field=key=value]... [--json]

quickpay payments:list [--accepted] [--state=value] [--order-id=value]
  [--created-after=value] [--created-before=value] [--page-size=20]
  [--all] [--max-pages=100] [--json]

quickpay payments:get <id> [--operations-size=value] [--json]

quickpay payments:link <id> <amount>
  [--continue-url=url] [--cancel-url=url] [--callback-url=url]
  [--language=value] [--payment-methods=value] [--auto-capture]
  [--field=key=value]... [--json]
```

`order-id` must contain 4–20 characters. Additional `--field` values support nested bracket notation such as `basket[0][qty]=2`; named arguments and options remain authoritative over conflicting fields.

Use `--operations-size=0` when the payment's operation list is not needed.

Human-readable operation tables include Quickpay's callback success, response
code, and timestamp when those fields are present.

### Mutate payments

```text
quickpay payments:capture <id> <amount>
  [--synchronized] [--callback-url=url] [--yes] [--json]

quickpay payments:refund <id> <amount>
  [--vat-rate=value] [--synchronized] [--callback-url=url]
  [--yes] [--json]

quickpay payments:cancel <id>
  [--synchronized] [--callback-url=url] [--yes] [--json]
```

### Develop callbacks locally

Quickpay cannot deliver a callback directly to a server that only exists on
your laptop. The `callbacks` commands bridge that gap by fetching the current
payment from Quickpay, constructing the documented callback headers and HMAC,
and POSTing the signed payment JSON to an explicit HTTP or HTTPS destination.

Replay the current state once while developing a callback handler:

```bash
quickpay callbacks:replay 884201 --to=http://127.0.0.1:8000/quickpay/callback
quickpay callbacks:replay --order-id=demo123 \
  --to=http://127.0.0.1:8000/quickpay/callback --json
```

Watch for future operations during a checkout flow:

```bash
quickpay callbacks:watch --order-id=demo123 \
  --to=http://127.0.0.1:8000/quickpay/callback
quickpay callbacks:watch 884201 \
  --to=http://127.0.0.1:8000/quickpay/callback --interval=2
```

Exactly one payment selector is required. `--interval` accepts whole seconds
from 1 through 60. Watch is a foreground, human-readable stream; press Ctrl-C
to stop it. It baselines operations already present when an existing payment is
selected. If an order does not exist yet, every operation present when its
payment first appears is forwarded.

Every detected operation produces one callback in operation order. If multiple
operations appear between polls, each POST necessarily contains the same latest
payment snapshot; the command warns when this happens. A failed delivery is
retried with the exact same captured body and signature before later operations
are sent. `2xx`, `302`, and `303` are successful; `301` and `307` are followed
up to five times while preserving POST, headers, and body. Redirects from HTTPS
to plaintext HTTP are refused.

The signing key comes from a non-empty `QUICKPAY_PRIVATE_KEY`, otherwise the CLI
retrieves `/account/private-key` using the active API key. It remains in memory
only. The callback destination receives no Quickpay `Authorization` or
`Accept-Version` header, local response bodies are never printed, TLS
verification stays enabled, and there is no `--insecure` option.

These commands reproduce Quickpay's callback envelope for the current resource;
they cannot reproduce a historical resource snapshot, original source IP, or
Quickpay's delivery timing. They are a foreground development aid, not a daemon
or durable webhook relay.

The existing `--callback-url` option on payment links and mutations has a
different purpose: it asks Quickpay's servers to deliver the real callback.
Therefore a localhost or otherwise private-only URL passed through
`--callback-url` is not reachable by Quickpay. Use `callbacks:watch` locally or
expose the handler through a tunnel you trust.

Complete forms:

```text
quickpay callbacks:replay [<payment-id>] --to=url
  [--order-id=value] [--json]

quickpay callbacks:watch [<payment-id>] --to=url
  [--order-id=value] [--interval=2]
```

### Raw API access

Use raw access for Quickpay v10 endpoints not covered by a first-class command:

```bash
quickpay api GET '/payments?order_id=demo123' --json
quickpay api GET /payments --query=order_id=demo123 --json
quickpay api GET /ping --header='X-Request-Id: demo123' --json
```

The complete form is:

```text
quickpay api <GET|POST|PUT|PATCH|DELETE> <relative-path>
  [--query=key=value]... [--data=key=value]... [--data-json='{}']
  [--header='name:value']... [--yes] [--json]
```

Raw paths must stay relative to `api.quickpay.net`. Full URLs, hosts, schemes, traversal segments, backslashes, userinfo, controls, and fragments are rejected. `--data` and `--data-json` are mutually exclusive. The client owns `Authorization`, `Host`, `Accept`, `Accept-Version`, and `Content-Type`; callers cannot override them.

Every non-`GET` raw request is treated as a mutation.

## Development

The source checkout uses Laravel Zero 12, Pest 4, Larastan/PHPStan, Pint, and Box. Composer 2.10.2 is pinned for reproducible PHAR builds.

```bash
composer install
composer check
composer coverage
composer audit --locked --abandoned=fail
```

The full test suite uses HTTP fakes and does not contact Quickpay.

Build and verify the tracked development PHAR:

```bash
composer build
builds/quickpay --version
builds/quickpay list --raw
php scripts/verify-phar-source.php builds/quickpay dev
```

See [CONTRIBUTING.md](CONTRIBUTING.md) before changing commands or security behavior. Maintainers should follow [docs/RELEASING.md](docs/RELEASING.md) for versioned releases.

## For Agents

The repository includes a QuickPay Agent Skill for coding agents:

```bash
skills add tehwave/quickpay-cli
```

## Security

Please read [SECURITY.md](SECURITY.md) before reporting a vulnerability. Never include API keys, authorization headers, real payment payloads, or merchant data in an issue.

GitHub release assets are published as immutable releases with build provenance.

After downloading a PHAR named `quickpay`, verify both attestations before
running it:

```bash
gh release verify v1.0.0 --repo tehwave/quickpay-cli
```

```bash
gh release verify-asset v1.0.0 quickpay --repo tehwave/quickpay-cli
```

```bash
gh attestation verify quickpay --repo tehwave/quickpay-cli
```

## License

Quickpay CLI is open-source software licensed under the [MIT License](LICENSE.md).

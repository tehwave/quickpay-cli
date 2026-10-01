---
name: quickpay-cli
description: Use when an agent needs to work with Quickpay payments, payment links, callbacks, capture, refund, cancel, authentication, or safe raw API access through the Quickpay CLI.
license: MIT
metadata:
  author: Peter Chr. Jorgensen
  project: tehwave/quickpay-cli
  version: dev
---

# Quickpay CLI

Run `quickpay --version` before use. Installation and the complete human-facing command reference are documented in the project README.

## Credentials

Have the human run `quickpay merchants:add <name>` themselves to enter an API
key privately. Never request, print, store, or pass a key in command arguments,
headers, files, logs, or output. Do not read credential files to discover keys.

Saved merchant access lives in `~/.config/quickpay-cli/config.json`. Names such
as `shop-a` are local labels, not verified merchant IDs. `merchants:list` lists
saved names; `merchants:select <name>` changes the global default;
`merchants:select <name> --local` writes only the selection to `.quickpay-cli.json`
in the current directory. `merchants:remove <name>` removes only local access,
never the remote Quickpay account. Only change persistent selection when the
user asks for it; prefer `--merchant=<name>` for one command.

Use `quickpay merchants:active --check --json` to verify API access for the
resolved credential. Add `--merchant=<name>` when working with a saved merchant,
and keep that same explicit selection for inspection and execution. Plain
`merchants:active` reports local configuration without checking authentication.

Selection is an explicit `--merchant`, then the nearest `.quickpay-cli.json`
found from the working directory upwards, then the global default. Unknown
names and malformed or stale bindings fail without falling back. A non-empty
`QUICKPAY_API_KEY` works independently of saved storage when there is no explicit
or project selection. Combining that environment key with either selection
fails; report the conflict rather than silently changing the environment or
using another merchant. Environment-only access has no inferred saved label.

The first saved merchant becomes the default; adding others keeps an established
default. Replacing a key requires `merchants:add <name> --replace` and private
human input; a rejected key preserves existing access. Removing the default
leaves no default, and bindings to removed names fail. The old `login`, `logout`
and `auth` commands and `~/.config/quickpay/config.json` are not supported.
Existing users add their merchants again; no automatic migration occurs.

## Safety workflow

- Treat `payments:list`, `payments:get`, and raw `api GET` as reads. Treat every other payment command and every non-`GET` raw API request as a mutation.
- Prefer `--json` for automation.
- Amounts use the smallest currency unit: `1000` is DKK 10.00.
- Before capture, refund, or cancel, run `quickpay payments:get <id> --merchant=<name> --json`; verify merchant identity, payment ID, order, currency, state, accepted status, balance, and the requested amount. Keep the same explicit merchant in subsequent commands. A saved label or project binding does not authorize a mutation.
- Do not infer mutation permission from read access. Require the user's explicit authorization of the operation, payment ID, and amount before any mutation.
- Use `--yes` only after that explicit authorization. It is required for unattended capture/refund/cancel and non-`GET` raw API requests; otherwise the CLI asks for an interactive confirmation.

Safe capture example, only after inspection and explicit authorization:

```bash
quickpay merchants:active --merchant=shop-a --check --json
quickpay payments:get 884201 --merchant=shop-a --json
quickpay payments:capture 884201 2500 --merchant=shop-a --yes --json
```

For a refund or cancellation, use the same inspect → verify → explicit authorization → execute sequence. If inspection is unavailable or the state/amount is wrong, stop and report it.

## Commands

```text
quickpay about
quickpay merchants:add <name> [--replace]
quickpay merchants:list [--json]
quickpay merchants:select [<name>] [--local]
quickpay merchants:active [--merchant=name] [--check] [--json]
quickpay merchants:remove <name>
quickpay payments:create <order-id> [currency=DKK] [--field=key=value]... [--merchant=name] [--json]
quickpay payments:list [--accepted] [--state=value] [--order-id=value]
  [--created-after=value] [--created-before=value] [--page-size=20]
  [--all] [--max-pages=100] [--merchant=name] [--json]
quickpay payments:get <id> [--operations-size=value] [--merchant=name] [--json]
quickpay payments:link <id> <amount> [--continue-url=url] [--cancel-url=url]
  [--callback-url=url] [--language=value] [--payment-methods=value]
  [--auto-capture] [--field=key=value]... [--merchant=name] [--json]
quickpay payments:capture <id> <amount> [--synchronized] [--callback-url=url]
  [--merchant=name] [--yes] [--json]
quickpay payments:refund <id> <amount> [--vat-rate=value] [--synchronized]
  [--callback-url=url] [--merchant=name] [--yes] [--json]
quickpay payments:cancel <id> [--synchronized] [--callback-url=url] [--merchant=name] [--yes] [--json]
quickpay callbacks:replay [<payment-id>] --to=url [--order-id=value] [--merchant=name] [--json]
quickpay callbacks:watch [<payment-id>] --to=url [--order-id=value] [--interval=2]
  [--delivery-attempts=5] [--merchant=name]
quickpay api <GET|POST|PUT|PATCH|DELETE> <path> [--query=key=value]...
  [--data=key=value]... [--data-json='{}'] [--header='name:value']...
  [--merchant=name] [--yes] [--json]
```

Without `--all`, `payments:list` returns one page and reports additional pages
on stderr when `--json` is active. Use `--all` when the task requires a complete
bounded result set.

`payments:create` and `payments:link` also alter remote state but do not expose `--yes`; require explicit authorization before using them. `--data` and `--data-json` cannot be combined.

## Local callback development

Use `callbacks:replay` with exactly one payment ID or `--order-id` selector to
send that payment's current resource once.

Use selector-free `callbacks:watch --to=url` to watch every payment operation
created after the watcher announces that it is ready. This account-wide mode
may forward data from any payment changed during the session. Add one payment
ID or `--order-id` to narrow the watch to a single payment. Providing both
selectors is invalid.

The `--to` URL is an outbound POST destination and may receive merchant or
transaction data. Require the user to provide or explicitly approve the exact
destination before running either command. Do not invent a public endpoint,
silently start a tunnel, or substitute `--callback-url`: that option tells
Quickpay's servers where to deliver and localhost is not reachable from them.

Watch has no JSON mode and does not replay existing operations. It runs until
the user stops it with Ctrl-C, a callback delivery fails terminally, or its
attempt limit is exhausted. Delivery is FIFO and never skips a blocked
operation. Each captured callback gets five attempts by default, including the
initial POST; `--delivery-attempts` accepts 1 through 60, and `--interval` is the
fixed delay between attempts. Network failures, HTTP 408, 425, 429, and 5xx
responses are retryable. Other HTTP failures and rejected redirects are
terminal. A terminal or exhausted failure leaves the operation undelivered,
blocks later operations, writes the safe failure to stderr, and exits 1. Fix the
endpoint and recover with:

```bash
quickpay callbacks:replay <payment-id> --to=<corrected-url>
```

`QUICKPAY_PRIVATE_KEY` is an optional sensitive environment override; never
ask the user to paste it into chat or place it in a command argument. Without
it, the CLI retrieves the key through the authenticated API and retains it only
in memory. An explicit override must belong to the selected merchant. A watcher
keeps the merchant and key resolved at startup even if saved selection changes.

## Raw API guardrails

Use only safe relative paths under `api.quickpay.net`, for example:

```bash
quickpay api GET '/payments?order_id=demo123' --json
```

Do not use full URLs, hosts, schemes, `..`, backslashes, or fragments. The CLI owns authentication and versioning: never override `Authorization`, `Host`, or `Accept-Version`. Custom headers must be `name:value` and must not contain a key.

## Quick reference and common mistakes

- Inspect command spelling is plural and colon-separated: `payments:get`, not `payment get`.
- Capture/refund positional order is `<id> <amount>`.
- `--yes` is the non-interactive transport flag, not user authorization.
- A raw `GET` needs no `--yes`; a raw `POST`, `PUT`, `PATCH`, or `DELETE` does.
- `quickpay merchants:active --check --json` checks API access; never replace it with printing or testing a key in an argument.
- `merchants:active` without `--check` does not prove that a credential works.
- Use the same `--merchant` for readiness checks, payment inspection and any explicitly authorized mutation.

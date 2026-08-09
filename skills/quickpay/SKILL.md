---
name: quickpay
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

Have the human run interactive `quickpay login` themselves. Never request, print, store, or pass an API key in command arguments, headers, files, logs, or output. Use `quickpay auth --check --json` when automation must verify that a credential is active; plain `quickpay auth` remains a human-readable status command. The CLI uses a non-empty `QUICKPAY_API_KEY` before `~/.config/quickpay/config.json`; `quickpay logout` only removes the stored file.

## Safety workflow

- Treat `payments:list`, `payments:get`, and raw `api GET` as reads. Treat every other payment command and every non-`GET` raw API request as a mutation.
- Prefer `--json` for automation.
- Amounts use the smallest currency unit: `1000` is DKK 10.00.
- Before capture, refund, or cancel, run `quickpay payments:get <id> --json`; verify payment ID, order, currency, state, accepted status, balance, and the requested amount.
- Do not infer mutation permission from read access. Require the user's explicit authorization of the operation, payment ID, and amount before any mutation.
- Use `--yes` only after that explicit authorization. It is required for unattended capture/refund/cancel and non-`GET` raw API requests; otherwise the CLI asks for an interactive confirmation.

Safe capture example, only after inspection and explicit authorization:

```bash
quickpay payments:get 884201 --json
quickpay payments:capture 884201 2500 --yes --json
```

For a refund or cancellation, use the same inspect → verify → explicit authorization → execute sequence. If inspection is unavailable or the state/amount is wrong, stop and report it.

## Commands

```text
quickpay about
quickpay login
quickpay auth [--check] [--json]
quickpay logout
quickpay payments:create <order-id> [currency=DKK] [--field=key=value]... [--json]
quickpay payments:list [--accepted] [--state=value] [--order-id=value]
  [--created-after=value] [--created-before=value] [--page-size=20]
  [--all] [--max-pages=100] [--json]
quickpay payments:get <id> [--operations-size=value] [--json]
quickpay payments:link <id> <amount> [--continue-url=url] [--cancel-url=url]
  [--callback-url=url] [--language=value] [--payment-methods=value]
  [--auto-capture] [--field=key=value]... [--json]
quickpay payments:capture <id> <amount> [--synchronized] [--callback-url=url] [--yes] [--json]
quickpay payments:refund <id> <amount> [--vat-rate=value] [--synchronized]
  [--callback-url=url] [--yes] [--json]
quickpay payments:cancel <id> [--synchronized] [--callback-url=url] [--yes] [--json]
quickpay callbacks:replay [<payment-id>] --to=url [--order-id=value] [--json]
quickpay callbacks:watch [<payment-id>] --to=url [--order-id=value] [--interval=2]
  [--delivery-attempts=5]
quickpay api <GET|POST|PUT|PATCH|DELETE> <path> [--query=key=value]...
  [--data=key=value]... [--data-json='{}'] [--header='name:value']... [--yes] [--json]
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
in memory.

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
- `quickpay auth` is for status; it must never be replaced with printing or testing a key in an argument.

# Security Policy

Quickpay CLI handles payment operations and API credentials. Please report security issues privately and avoid creating a public issue until a fix is available.

## Supported versions

The latest stable release receives security fixes. The `main` branch contains the next release and may change without notice.

## Reporting a vulnerability

Use [GitHub private vulnerability reporting](https://github.com/tehwave/quickpay-cli/security/advisories/new).

Include:

- the affected command or component;
- the security impact;
- minimal reproduction steps using fake credentials and test data;
- the affected version or commit;
- any suggested mitigation.

Do not include API keys, authorization headers, real payment payloads, merchant identifiers, personal data, or secrets in the report. Replace sensitive values with unmistakably fake placeholders.

If private vulnerability reporting is unavailable, do not publish vulnerability
details in an issue. Contact the maintainer through the contact information on
their GitHub profile and ask for a private reporting channel without including
the report itself.

## Scope

Security-relevant areas include:

- credential precedence, persistence, permissions, and redaction;
- raw API origin, path, header, query, and body handling;
- payment mutation confirmation and non-interactive safeguards;
- response validation and stdout/stderr separation;
- pagination origin validation, bounds, and cycle detection;
- callback destination validation, HMAC construction, redirect handling, and
  separation between Quickpay-authenticated requests and local forwarding;
- callback private-key retrieval and in-memory-only handling;
- release artifact contents, checksums, and build provenance.

## Local callback forwarding

`callbacks:replay` and `callbacks:watch` send a signed payment resource to the
HTTP or HTTPS URL explicitly supplied with `--to`. Loopback HTTP is supported
for local development; TLS verification is always enabled for HTTPS. The CLI
rejects userinfo, fragments, controls, missing hosts, malformed ports, and
non-HTTP schemes. An HTTPS destination cannot redirect a signed payload to
plaintext HTTP.

The forwarder is separate from the Quickpay API client. It does not send the
API key, Basic authorization, or Quickpay API request headers to the callback
destination. The account private key comes from a non-empty
`QUICKPAY_PRIVATE_KEY` or the authenticated `/account/private-key` endpoint and
is retained in process memory only. It is never written to the CLI config,
stdout, stderr, or local response summaries.

Because `--to` may name a public service, inspect it before running the command.
The explicit destination is the authorization to POST the payment payload; the
commands do not add a second confirmation prompt. Avoid destinations you do not
control, since payment callbacks contain merchant and transaction data even
after credential redaction.

Running `callbacks:watch` without a payment ID or `--order-id` watches the
account. It may forward data from any payment changed during that session, not
only the payment involved in the developer's current checkout flow. Use a
selector when the destination should receive data for only one payment.

Callback delivery is bounded to five attempts by default, including the initial
POST; `--delivery-attempts` accepts limits from 1 through 60. The same captured
body and HMAC signature are reused for every attempt, with the fixed
`--interval` delay only before another attempt. Network failures, HTTP 408, 425,
429, and 5xx responses are retryable. Other unsuccessful HTTP responses and
rejected redirects are terminal; callback destinations cannot extend delivery
with `Retry-After` or request unlimited retries.

The watcher preserves FIFO delivery. A failed operation is never marked
delivered, and no later operation is forwarded after a terminal or exhausted
failure. The command reports safe HTTP or no-response context on stderr and
exits with status 1. After fixing the endpoint, explicitly recover the blocked
payment with the corrected `--to` URL:

```bash
quickpay callbacks:replay <payment-id> --to=<corrected-url>
```

Quickpay's hosted API, Manager, payment window, and merchant configuration are outside this project's control and should be reported to Quickpay through its official channels.

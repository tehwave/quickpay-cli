# Quickpay CLI

`quickpay` is a PHP command-line client for the [Quickpay API](https://learn.quickpay.net/tech-talk/api/). It is intended for merchant-side payment inspection and operations: creating payments and payment links, listing and inspecting payments, and carefully capturing, refunding, or cancelling them. It is not a checkout UI, an acquirer configuration tool, or a replacement for the Quickpay Manager.

The package is prepared for a future public Composer/Packagist release, but it is **not published yet**: this repository has no remote, release, tag, or Packagist package at present.

## Requirements and installation

- PHP 8.4 or newer
- Composer

Reproducible PHAR builds use Composer 2.8.9. Continuous integration pins that version explicitly.

### Local development

Clone or enter this checkout, then install its dependencies:

```bash
composer install
php quickpay --version
php quickpay list
```

The source launcher is `php quickpay`; use it while developing the project.

### Future global installation

After a public release, the intended installation will be:

```bash
composer global require peterchrjoergensen/quickpay-cli
quickpay --version
```

If `quickpay` is not found, add Composer's global bin directory to `PATH`. Discover its exact location on the machine with:

```bash
composer global config bin-dir --absolute
```

Then open a new shell and run `quickpay --version`. Until publication, the global-require command and `skills add peterchrjoergensen/quickpay-cli` are only future instructions and will not install anything.

## Authentication and credentials

Before using API commands, a human operator must run the interactive login themselves:

```bash
quickpay login
quickpay auth
quickpay logout
```

`login` asks for a merchant API key without echoing it, checks it against Quickpay, and stores it at `~/.config/quickpay/config.json`. The directory is created with mode `0700` and the config file with mode `0600`. `auth` reports the active credential source, API endpoint/version, and scope; it never prints the key. `logout` removes only the stored config.

Credential precedence is `QUICKPAY_API_KEY` (when non-empty), then the config file. An environment key remains active after `logout`; unset it to fully log out. Use an environment variable only through a secret manager or the process environment—never put a real key in shell history, command arguments, scripts, logs, screenshots, issue text, or committed files. Do not use `--header Authorization:…` either.

## Output, amounts, and safety

Commands show concise human-readable tables/details by default. Add `--json` for the machine-readable response form; it is the preferred mode for automation. Mutation safety messages may still be written to stderr so stdout remains usable as JSON.

All amounts are integers in the currency's smallest unit. For Danish kroner, `1000` means DKK 10.00—not DKK 1,000.00.

Read commands are safe to automate. `payments:create` and `payments:link` create remote resources, so run them only with clear operator intent. `payments:capture`, `payments:refund`, `payments:cancel`, and every non-`GET` raw `api` request print a summary and request confirmation when interactive. In a non-interactive run they require `--yes`; use that flag only after explicit authorization of the exact operation, payment, and amount. The mutation commands also fetch the payment before acting, but inspect it yourself first for an auditable workflow.

## Payment commands

Every payment command accepts `--json` where shown below.

| Command | Arguments and options |
| --- | --- |
| `payments:create` | `quickpay payments:create <order-id> [currency=DKK] [--field=key=value]... [--json]` |
| `payments:list` | `quickpay payments:list [--accepted] [--state=value] [--order-id=value] [--created-after=value] [--created-before=value] [--page-size=20] [--all] [--max-pages=100] [--json]` |
| `payments:get` | `quickpay payments:get <id> [--operations-size=value] [--json]` |
| `payments:link` | `quickpay payments:link <id> <amount> [--continue-url=url] [--cancel-url=url] [--callback-url=url] [--language=value] [--payment-methods=value] [--auto-capture] [--field=key=value]... [--json]` |
| `payments:capture` | `quickpay payments:capture <id> <amount> [--synchronized] [--callback-url=url] [--yes] [--json]` |
| `payments:refund` | `quickpay payments:refund <id> <amount> [--vat-rate=value] [--synchronized] [--callback-url=url] [--yes] [--json]` |
| `payments:cancel` | `quickpay payments:cancel <id> [--synchronized] [--callback-url=url] [--yes] [--json]` |

`order-id` must be 4–20 characters. `--field=key=value` passes additional Quickpay request fields; named options win where both address the same value. Use `--all` to follow list pagination, bounded by `--max-pages`. `--operations-size=0` is allowed when an operation list is not needed.

A safe capture sequence, after the human has authorized it, is:

```bash
quickpay payments:get 884201 --json
# Verify id, order, currency, state, accepted status, balance, and authorized amount.
quickpay payments:capture 884201 2500 --yes --json
```

Use the same inspect-first process for refunds and cancellations. Never infer permission to capture, refund, cancel, create, or link a payment from permission to read it.

## Raw API access

Use raw access only for API endpoints not covered above:

```bash
quickpay api GET '/payments?order_id=demo123' --json
quickpay api GET /payments --query=order_id=demo123 --json
quickpay api GET /ping --header='X-Request-Id: demo123' --json
```

The complete form is:

```text
quickpay api <GET|POST|PUT|PATCH|DELETE> <relative-path> \
  [--query=key=value]... [--data=key=value]... [--data-json='{}'] \
  [--header='name:value']... [--yes] [--json]
```

Paths must be safe relative paths under `api.quickpay.net`; URLs, hosts, schemes, `..`, backslashes, and fragment-like paths are rejected. `--data` and `--data-json` are mutually exclusive. The client owns authentication and API versioning, so `Authorization`, `Host`, and `Accept-Version` headers cannot be overridden. Non-`GET` raw requests are mutations: inspect first, obtain explicit authorization, then use `--yes` only for an unattended execution.

## Test payments and documentation

Use Quickpay's test transactions during integration work and make the receiving system recognise them from the callback. The current official list of test cards, mobile numbers, and expected outcomes is [Quickpay test data](https://learn.quickpay.net/tech-talk/appendixes/test/). Test transactions can be disabled per merchant in Quickpay Manager, so verify the merchant's integration settings before relying on them.

The CLI currently targets Quickpay API version `v10`. Consult the [API reference](https://learn.quickpay.net/tech-talk/api/) and [Quickpay technical documentation](https://learn.quickpay.net/tech-talk/) for endpoint and payment-flow details.

## Development, build, and release readiness

Run the local quality checks with:

```bash
composer test
composer analyse
composer format:test
composer format
composer check
```

Build the distributable PHAR and smoke-test it with:

```bash
composer build
builds/quickpay --version
builds/quickpay list
php scripts/verify-phar-source.php builds/quickpay dev
```

`composer build` first runs a fresh lock-file install with `COMPOSER_ROOT_VERSION=dev-main`, then invokes Box. This keeps Composer's generated root package identity stable; use Composer 2.8.9 for the same generated dependency metadata as continuous integration.

`composer.json` exposes `builds/quickpay` as the Composer bin target. That built executable is intentionally tracked so a future Composer package archive contains the file its bin entry references. Other build artifacts remain ignored.

The integrity verifier derives the complete expected archive from `box.json`, the checkout, `composer.json`, `composer.lock`, and the installed Box compiler. It checks the exact packaged file set across application, bootstrap, configuration, launcher, Composer, vendor, and Box runtime files, plus the executable stub. PHP is compared byte-for-byte after replaying the exact installed Box PHP compactor. JSON and all other files are compared byte-for-byte without semantic normalization, which preserves object/list distinctions and integers larger than 64 bits. Only proven Composer install volatility is normalized: this project's generated root identity and root classmap entries, Composer's generated initializer suffix, and Pest's plugin ordering. Dependency metadata and autoload entries remain exact. Box ignores hidden development metadata inside configured directories; no executable PHP or security metadata is otherwise excluded from verification.

There is no self-update command. After publication, updates will use Composer, for example `composer global update peterchrjoergensen/quickpay-cli`.

### Future release procedure — not yet performed

1. Choose and set the intended release version.
2. With Composer 2.8.9, run `COMPOSER_ROOT_VERSION=dev-main composer install --no-interaction --no-progress --prefer-dist`, then `composer check`.
3. Rebuild the tracked PHAR with that exact version, for example `php quickpay app:build quickpay --build-version=1.2.3 --no-interaction`; do not tag a release built as `dev`.
4. Run `builds/quickpay --version`, `builds/quickpay list`, and `php scripts/verify-phar-source.php builds/quickpay 1.2.3`, then review the diff and verify no credential/config file is included.
5. Commit the source and rebuilt `builds/quickpay`, tag the verified commit, then create the GitHub release and publish the matching Packagist package.

Only after public publication will the agent reference skill be installable with:

```bash
skills add peterchrjoergensen/quickpay-cli
```

## License

MIT. See [LICENSE.md](LICENSE.md).

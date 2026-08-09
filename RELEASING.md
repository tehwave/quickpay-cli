# Releasing Quickpay CLI

This runbook separates source publication from generated artifacts. Replace
`1.0.0` with the intended version and `v1.0.0` with its tag.

## Distribution model

The repository contains source code and the `quickpay` Composer binary. It does
not contain a built PHAR.

- Packagist users install the source package and its runtime dependencies with
  Composer.
- GitHub releases additionally provide a standalone `quickpay` PHAR, checksum,
  and tar archive.
- `.github/workflows/build-release.yml` builds those files from a selected
  source commit and uploads temporary workflow artifacts. It never commits a
  generated binary.
- A published tag and its release assets are immutable. Fix release mistakes
  with a new version.

Release builds use Composer 2.10.2 so local and CI packaging use the same
Composer-generated metadata. Advance the pin when intentionally upgrading the
release toolchain or responding to a Composer security release.

## 1. Prepare repository controls

Before the first public release, make `tehwave/quickpay-cli` public and confirm
Issues and Actions are enabled. Configure:

- immutable GitHub releases;
- a protected default branch requiring the `Quality` jobs;
- a protected `v*` tag pattern restricted to maintainers;
- read-only default Actions permissions;
- dependency graph, Dependabot alerts and security updates, secret scanning,
  push protection, and private vulnerability reporting;
- GitHub-authored actions plus `shivammathur/setup-php`, with actions pinned to
  full commit SHAs.

Do not add Quickpay credentials, Packagist credentials, personal access tokens,
or signing keys to the build workflow. The build job requires only source-read
access. The provenance job receives identity-token permission but never runs
repository code or the PHAR.

Do not submit the package to Packagist until the public repository, default
branch, license, README, and first version tag are ready.

## 2. Choose and document the version

Use `1.0.0` only if the public command and safety contracts are ready to be
stable. In `CHANGELOG.md`, move the relevant entries beneath:

```markdown
## [1.0.0] - YYYY-MM-DD
```

Keep an empty `[Unreleased]` section above it. The release version is embedded
when the PHAR is built; there is no source-code version constant to edit.

## 3. Verify the source commit

Work from an up-to-date branch with no unrelated changes:

```bash
git status --short
git pull --ff-only origin main
COMPOSER_ROOT_VERSION=dev-main composer install --no-interaction --no-progress --prefer-dist
composer verify
git diff --check
```

Expected results:

- all Pest suites pass without contacting Quickpay;
- application coverage is at least 90%;
- PHPStan and Pint pass;
- Composer metadata is valid and the lock has no known advisories;
- the working tree contains only intentional release changes.

Commit the source and changelog. Wait for every `Quality` job on the exact
commit to pass.

## 4. Build the release artifacts

Locally, build into the ignored `builds/release` directory:

```bash
composer release:build -- 1.0.0
```

The script installs the locked development graph, runs `composer verify`,
reinstalls runtime-only dependencies, builds the versioned PHAR, smoke-checks
its version and command list, and creates:

```text
builds/release/quickpay
builds/release/quickpay.sha256
builds/release/quickpay-1.0.0.tar.gz
```

Generated files remain ignored and must not be committed.

The preferred release build is **Actions → Build release binary → Run
workflow** on the exact verified commit. Enter the version without a `v`
prefix. The workflow runs the same script, uploads the three files for 14 days,
and creates build-provenance attestation for the PHAR. It does not push, tag,
publish a release, publish to Packagist, or contact Quickpay.

Download the workflow artifact and verify:

```bash
./quickpay --version
./quickpay list --raw
shasum -a 256 -c quickpay.sha256
```

The version must be `Quickpay 1.0.0`, the command list must match the README,
and the checksum must pass. Inspect the PHAR contents for unexpected local
configuration, credentials, development packages, or test files.

## 5. Smoke-test the remote contract

Automated tests deliberately use HTTP fakes. Before tagging, use the downloaded
release candidate and a dedicated Quickpay test merchant for a minimal remote
contract check:

```bash
QUICKPAY_API_KEY="$QUICKPAY_TEST_API_KEY" ./quickpay auth
QUICKPAY_API_KEY="$QUICKPAY_TEST_API_KEY" ./quickpay payments:list --page-size=1 --json
```

Load `QUICKPAY_TEST_API_KEY` through a secret manager without saving it in shell
history. Do not capture merchant or payment data, and unset the variable after
the smoke test.

Creating a payment or payment link creates a remote resource even on a test
merchant. Captures, refunds, cancellations, and raw non-`GET` requests are
payment mutations. Exercise them only when the exact test operation, payment,
and amount have been separately authorized. Never use a production merchant for
a release smoke test.

## 6. Tag the verified source commit

After the release changes are merged, update local `main` and confirm it is the
same source commit used by the successful artifact build:

```bash
git switch main
git pull --ff-only origin main
git status --short
git rev-parse HEAD
```

Create and push a signed tag:

```bash
git tag -s v1.0.0 -m "Quickpay CLI 1.0.0"
git tag -v v1.0.0
git push origin v1.0.0
```

Never move or replace a published release tag.

## 7. Publish the GitHub release

Create a draft release from `v1.0.0`:

- title: `Quickpay CLI 1.0.0`;
- notes: summarize the dated changelog and repeat the credential and mutation
  safety model;
- assets: the workflow-produced `quickpay`, `quickpay.sha256`, and
  `quickpay-1.0.0.tar.gz`;
- include the SHA-256 value in the notes;
- do not mark a stable version as a prerelease.

Review everything before publishing. After publication, verify the immutable
release and artifact provenance:

```bash
gh release verify v1.0.0 --repo tehwave/quickpay-cli
gh release verify-asset v1.0.0 quickpay --repo tehwave/quickpay-cli
gh attestation verify quickpay --repo tehwave/quickpay-cli
```

Independently recheck the downloaded checksum and version. If anything is
wrong, publish a new patch version; never replace a published asset.

## 8. Publish and verify Packagist

Submit `https://github.com/tehwave/quickpay-cli` to Packagist. Confirm it finds
`v1.0.0`, PHP `^8.4`, the MIT license, source/support links, Laravel Zero as a
runtime dependency, and `quickpay` as the Composer binary.

Verify a clean source-package install in a disposable Composer environment:

```bash
composer global require tehwave/quickpay-cli:^1.0
quickpay --version
quickpay list --raw
quickpay auth
```

`quickpay auth` should report that no credential is configured and must not
contact Quickpay without a key. After the public package is discoverable, also
verify:

```bash
npx skills add tehwave/quickpay-cli
```

## 9. After publication

- Confirm the README installation path works anonymously.
- Confirm `Quality` passes on public `main`.
- Confirm private vulnerability reporting is available.
- Add release comparison links to `CHANGELOG.md` when applicable.
- Announce only after both the GitHub artifact and Packagist install have been
  verified.

Fix release problems forward with a new patch version such as `1.0.1`.

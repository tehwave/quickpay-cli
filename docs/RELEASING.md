# Releasing Quickpay CLI

This runbook separates reversible preparation from public publication. Replace `1.0.0` with the intended version and `v1.0.0` with the matching tag.

Composer 2.10.2 is required because generated Composer metadata is part of the byte-verified PHAR. This pin must be advanced promptly when the Composer project publishes a security fix.

## Distribution model

Composer consumers install the tracked `builds/quickpay` executable; they do not
need Laravel Zero in their own dependency graph. Laravel Zero therefore remains
a development dependency even though Box must embed it in the PHAR.

`box.json` deliberately keeps `dump-autoload` and `exclude-dev-files` disabled.
The first setting preserves the checked-in Composer autoloader so the integrity
verifier can compare the archive to the checkout byte for byte. Box disables
development-package exclusion in that mode because removing packages without
regenerating the autoloader can leave references to missing files. The trade-off
is a larger PHAR that includes build and test tooling. Prefer that explicit,
verified artifact over a smaller archive with a stale autoloader; changing this
model requires a separate production dependency tree and corresponding verifier
coverage.

## 1. Prepare the repository

Before the first public release, the repository owner must make
`tehwave/quickpay-cli` public, set its description to
`A safety-focused command-line client for the Quickpay API.`, set its homepage
to `https://peterchrjoergensen.dk`, add the `quickpay`, `php`, `cli`, `payments`,
`laravel-zero`, and `phar` topics, and confirm Issues and Actions are enabled.

Configure these security controls before running the release workflow:

### Releases

- In **Settings → General → Releases**, enable release immutability. It protects
  only releases published after the setting is enabled.
- Always create a draft release, attach every asset and the checksum, review the
  notes, then publish it. Publishing locks the tag and assets and creates
  GitHub's release attestation.

### Actions

- In **Settings → Actions → General**, keep the default workflow permission at
  **Read repository contents and packages permissions** and leave permission to
  create or approve pull requests disabled.
- Allow actions created by GitHub plus `shivammathur/setup-php`, and enable
  **Require actions to be pinned to a full-length commit SHA**. The repository
  workflows pin every action and Dependabot keeps those pins current.
- Do not add Quickpay, Packagist, signing, or personal access tokens to the build
  workflow. Its build job has read-only source access and cannot request identity
  tokens. The attestation job can request an identity token but does not execute
  repository code or the PHAR. Only the final commit job can write repository
  contents, and it also never executes repository code or the PHAR.

### Dependency and secret security

- In **Settings → Advanced Security**, enable the dependency graph, Dependabot
  alerts, Dependabot security updates, grouped security updates, secret
  scanning, push protection, and private vulnerability reporting.
- Keep `.github/dependabot.yml` enabled for Composer and GitHub Actions. After
  the repository is public, the `Dependency review` check rejects vulnerable
  dependency additions in pull requests.
- Subscribe to repository **Security alerts** notifications so private reports,
  leaked-secret alerts, and vulnerable dependency alerts are not missed.

### Branches and tags

- Create an active branch ruleset for the default branch. Require a pull request,
  require every `Quality` job on the latest commit, require the branch to be up
  to date, require linear history, and block force pushes and deletion. A solo
  maintainer can use zero required approvals; add a review requirement when a
  second trusted maintainer is available.
- Create an active tag ruleset targeting `v*`. Restrict tag creation to the
  maintainer and restrict updates and deletion. Do not give GitHub Actions a tag
  bypass: this repository creates and publishes releases manually.
- Run the binary workflow only on a dedicated release branch such as
  `release/1.0.0`. The workflow refuses the default branch and pushes the PHAR
  only if that branch still points at the exact source commit it built.

Do not submit the package to Packagist until the public repository, default branch, license, README, and first version tag are ready.

## 2. Choose the release

For the initial stable launch, use `1.0.0` only if the documented command surface and safety contract are intended to be stable. Review [CHANGELOG.md](../CHANGELOG.md), move the unreleased entries under:

```markdown
## [1.0.0] - YYYY-MM-DD
```

Keep an empty `[Unreleased]` section above it.

The application version is embedded during the PHAR build; there is no separate source-code version constant.

## 3. Verify the source checkout

Start from an up-to-date release branch with no unrelated changes:

```bash
git status --short
git pull --ff-only origin main
COMPOSER_ROOT_VERSION=dev-main composer install --no-interaction --no-progress --prefer-dist
composer validate --strict
composer audit --locked --abandoned=fail
composer check
composer coverage
git diff --check
```

Expected:

- 0 Composer validation errors;
- 0 known dependency advisories;
- all Pest tests pass;
- PHPStan level 8 and Pint pass;
- total application coverage is at least 90%;
- no unexpected working-tree changes.

The automated suite must use HTTP fakes. Do not run live payment mutations as part of release verification.

## 4. Build the versioned PHAR

Build with the release version, not `dev`. The script installs the locked
dependencies with the reproducible Composer identity, runs every quality and
coverage gate, builds and byte-verifies the PHAR, and writes download artifacts
under `builds/release`:

```console
scripts/build-release 1.0.0
```

Alternatively, open **Actions → Build release binary → Run workflow**, select
the branch that should receive the binary, and enter the version without a `v`
prefix. The manually dispatched workflow calls the same release script, uploads
the verified binary, checksum, and a permission-preserving tar archive for 14
days, then commits only
`builds/quickpay` back to the selected branch.

The selected release branch must permit the GitHub Actions bot to push. The
workflow refuses the default branch, tag refs, unexpected working-tree changes,
and commits containing anything besides `builds/quickpay`. A concurrent branch
update makes the push fail instead of being rebased or overwritten. The build
job has only read permission. A separate job that never executes project code
creates the signed build-provenance attestation, and a third minimal job
downloads and commits the PHAR without executing it. The workflow does not open
a pull request, create a tag or release, publish to Packagist, or contact
Quickpay; those release steps remain manual.

Verify:

- `builds/quickpay --version` reports `Quickpay 1.0.0`;
- the raw command list contains only the documented public commands;
- the integrity verifier reports no missing, extra, or mismatched files;
- the PHAR contains no credential file, `.env`, API key, or local configuration;
- the SHA-256 value is saved for the GitHub release.

Review the complete change:

```bash
git status --short
git diff --check
git diff --stat
git diff -- README.md CHANGELOG.md composer.json composer.lock builds/quickpay
```

When building locally, commit the source, changelog, and rebuilt
`builds/quickpay` together. When using the workflow, commit the source and
changelog to the selected branch first; the workflow adds the verified PHAR in
its own commit. Open a pull request and wait for every `Quality` job to pass.

## 5. Smoke-test the Quickpay contract

Automated tests deliberately use HTTP fakes. Before tagging, use a dedicated
Quickpay test merchant to confirm the exact versioned PHAR still matches the
remote v10 contract:

```bash
QUICKPAY_API_KEY="$QUICKPAY_TEST_API_KEY" builds/quickpay auth
QUICKPAY_API_KEY="$QUICKPAY_TEST_API_KEY" builds/quickpay payments:list --page-size=1 --json
```

Populate `QUICKPAY_TEST_API_KEY` through a secret manager without saving the
credential in shell history. Do not capture command output that contains
merchant or payment data, and unset the variable after the smoke test.

Creating a payment or payment link still creates a remote resource, even on a
test merchant. Captures, refunds, cancellations, and raw non-`GET` requests are
payment mutations. Exercise those flows only when the exact test operation,
payment, and amount have been separately authorized; never use a production
merchant for a release smoke test.

## 6. Tag the verified commit

After the release pull request is merged, update local `main` and confirm the committed artifact again:

```bash
git switch main
git pull --ff-only origin main
builds/quickpay --version
php scripts/verify-phar-source.php builds/quickpay 1.0.0
git status --short
```

Create and push a signed tag. Git can use a GPG or SSH signing key configured for
your GitHub identity:

```bash
git tag -s v1.0.0 -m "Quickpay CLI 1.0.0"
git tag -v v1.0.0
git push origin v1.0.0
```

Never move or replace a published release tag.

## 7. Publish the GitHub release

Create a **draft** GitHub release from the existing `v1.0.0` tag:

- title: `Quickpay CLI 1.0.0`;
- release notes: summarize the dated changelog section and repeat the
  mutation/credential safety model;
- attach the workflow artifacts as `quickpay`, `quickpay.sha256`, and
  `quickpay-1.0.0.tar.gz`;
- repeat the SHA-256 checksum in the release notes;
- do not mark a stable release as a prerelease.

Review the draft carefully before publishing because immutable release assets
cannot be replaced or removed afterward. After publication, download the PHAR
and verify both GitHub's immutable-release attestation and the build provenance:

```bash
gh release verify v1.0.0 --repo tehwave/quickpay-cli
gh release verify-asset v1.0.0 quickpay --repo tehwave/quickpay-cli
gh attestation verify quickpay --repo tehwave/quickpay-cli
```

Then independently verify its saved checksum and smoke tests. If anything is
wrong, publish a new patch version; never delete or rewrite a published release.

## 8. Publish to Packagist

1. Sign in to Packagist with the account that should own `tehwave/quickpay-cli`.
2. Submit `https://github.com/tehwave/quickpay-cli`.
3. Confirm Packagist discovers `v1.0.0` and displays PHP `^8.4`, the MIT license, source/support links, and `builds/quickpay` as the binary.
4. Enable GitHub/Packagist automatic updates if they were not connected during submission.

Packagist consumers should receive the tracked PHAR without installing Laravel Zero as a runtime dependency.

## 9. Verify a clean consumer install

Use an empty temporary Composer home or a disposable environment:

```bash
composer global require tehwave/quickpay-cli:^1.0
quickpay --version
quickpay list --raw
quickpay auth
```

Verify the version and public command list. `quickpay auth` should report that no credential is configured and must not contact Quickpay without a key.

Also verify the agent reference only after the public package is discoverable:

```bash
skills add tehwave/quickpay-cli
```

## 10. After publication

- Confirm the README badge and installation command work anonymously.
- Confirm the `Quality` workflow passes on public `main`.
- Confirm the GitHub security-advisory link is available.
- Add the released version and comparison links to `CHANGELOG.md` if desired.
- Announce the release only after both GitHub and Packagist installs have been verified.

If a release problem is found, stop promotion and fix forward with a new patch version such as `1.0.1`. Do not rewrite the published tag or silently replace its PHAR.

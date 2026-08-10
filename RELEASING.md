# Releasing Quickpay CLI

Packagist installs the committed `builds/quickpay` PHAR. A release is built
once, reviewed by `Quality`, committed, tagged, and published manually.

## One-time setup

- Connect the GitHub repository to Packagist.
- Require the `Quality` workflow on `main`.
- Restrict release tags to maintainers.
- Enable immutable GitHub Releases.

## Release

Start from current `main`, create a release branch, and update `CHANGELOG.md`.
Before building, that changelog must be the only working-tree change.

```bash
git switch main
git pull --ff-only origin main
git switch -c release/1.0.0

composer verify
php quickpay app:build quickpay \
    --build-version=1.0.0 \
    --no-interaction

test "$(builds/quickpay --version)" = "Quickpay 1.0.0"
builds/quickpay list --raw
git diff --check

git add CHANGELOG.md builds/quickpay
git commit -m "Release Quickpay 1.0.0"
git push -u origin release/1.0.0
```

Open a pull request and merge it after `Quality` passes. Then update `main` and
create the signed tag from the merged release commit:

```bash
git switch main
git pull --ff-only origin main
test -z "$(git status --short)"
test "$(builds/quickpay --version)" = "Quickpay 1.0.0"

git tag -s 1.0.0 -m "Quickpay CLI 1.0.0"
git tag -v 1.0.0
git push origin 1.0.0
```

Create the GitHub Release for that tag in the GitHub UI. Upload
`builds/quickpay`, mark it as a prerelease when appropriate, review it, and
publish it. Packagist synchronizes the tag automatically.

## Verify

Download the published `quickpay` asset and verify the immutable release, then
test a clean Composer installation:

```bash
gh release verify 1.0.0 --repo tehwave/quickpay-cli
gh release verify-asset 1.0.0 quickpay --repo tehwave/quickpay-cli
git tag -v 1.0.0
test "$(git show 1.0.0:builds/quickpay | shasum -a 256 | cut -d ' ' -f 1)" = \
    "$(shasum -a 256 quickpay | cut -d ' ' -f 1)"

quickpay_composer_home="$(mktemp -d /tmp/quickpay-composer.XXXXXX)"
COMPOSER_HOME="$quickpay_composer_home" composer global require \
    tehwave/quickpay-cli:1.0.0
"$quickpay_composer_home/vendor/bin/quickpay" --version
"$quickpay_composer_home/vendor/bin/quickpay" list --raw
COMPOSER_HOME="$quickpay_composer_home" composer global show
```

The Composer package list must contain only `tehwave/quickpay-cli`. Fix release
mistakes in a new version; never replace a published tag or release.

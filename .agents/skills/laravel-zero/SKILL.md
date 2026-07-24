---
name: laravel-zero
description: Use when creating, maintaining, testing, upgrading, or distributing PHP console applications built with Laravel Zero, including commands, service providers, configuration, add-ons, PHAR archives, and standalone binaries.
---

# Laravel Zero

## Overview

Laravel Zero is a Laravel-based micro-framework for console applications. Use official documentation for framework behavior, but treat repository instructions, locked dependencies, architecture, and release process as project contract.

## Workflow

1. Read `AGENTS.md`, the README, Composer files, app and command configuration, Box configuration, and nearby commands and tests.
2. Confirm the installed framework with `composer show --locked laravel-zero/framework`. Open the relevant official documentation before changing framework behavior. Because the website is unversioned, upgrades and compatibility questions also require the applicable upgrade section and source/release for the locked or target version.
3. Follow existing project structure and command conventions. Use the documentation's default pattern only where the repository has no established pattern.
4. Add Pest coverage in the project's style. Test observable results and the CLI contract: output, exit code, relevant prompts, and failure paths.
5. Run the focused test, the repository's full quality gate, and `git diff --check`. When packaged runtime code, configuration, dependencies, the launcher, or Box configuration changes, rebuild and smoke-test the artifact using the repository's release instructions.

## Official documentation map

| Task | Documentation |
| --- | --- |
| Orientation and new projects | [Introduction](https://laravel-zero.com/docs/introduction), [Installation](https://laravel-zero.com/docs/installation) |
| Commands and dependency injection | [Commands](https://laravel-zero.com/docs/commands) |
| Container bindings | [Service Providers](https://laravel-zero.com/docs/service-providers) |
| App and command registration | [Configuration](https://laravel-zero.com/docs/configuration) |
| Pest integration tests | [Testing](https://laravel-zero.com/docs/testing) |
| Optional components | Use the add-on linked from the [documentation index](https://laravel-zero.com/docs/introduction), then its Laravel documentation for the installed major |
| PHAR builds | [Distribute as a PHAR archive](https://laravel-zero.com/docs/distribute-as-a-phar-archive) |
| PHP-embedded binaries | [Distribute as a single executable binary](https://laravel-zero.com/docs/distribute-as-a-single-executable-binary) |
| Framework migrations | [Upgrade guide](https://laravel-zero.com/docs/upgrade) plus target-version source and release notes |

## Quick reference

- Commands normally live in `app/Commands`; scaffold with `php <app> make:command <Name>` and inject services into `handle()` when local conventions agree.
- Configuration files under `config` are auto-registered. `config/app.php` and `config/commands.php` are framework-owned essentials and must remain.
- A standard command test uses `$this->artisan('command:name')->expectsOutput(...)->assertExitCode(0)`; extend it with project-specific effects and error cases.
- PHAR builds use `php <app> app:build <build-name>` and `box.json`. Check current requirements, including sodium for PHAR bundling.
- For documented build diagnostics, retry with `-v`, then run the framework's Box compiler with `--debug`. Do not invent flags or weaken artifact verification.

## Example

```php
test('runs the report command', function () {
    $this->artisan('report:run')
        ->expectsOutput('Report complete.')
        ->assertExitCode(0);
});
```

## Common mistakes

- Assuming a full Laravel web-app structure instead of inspecting the console application.
- Using the latest Laravel documentation without matching the installed Laravel major.
- Treating the unversioned website as sufficient proof for an upgrade target.
- Writing mutable data inside a read-only PHAR instead of an external path.
- Editing a built PHAR directly or declaring success without rebuilding, smoke-testing, and applying the project's artifact checks.

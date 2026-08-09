<?php

use App\Console\ApplicationVersion;

it('uses a stable composer package version without a tag prefix', function (): void {
    expect((new ApplicationVersion)->resolve('v1.2.3', 'unreleased'))->toBe('1.2.3');
});

it('falls back to the development version for non-release composer versions', function (?string $packageVersion): void {
    expect((new ApplicationVersion)->resolve($packageVersion, 'unreleased'))->toBe('unreleased');
})->with([null, 'dev-main', '1.0.x-dev', '1.0.0+no-version-set']);

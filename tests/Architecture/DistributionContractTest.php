<?php

it('distributes the packaged binary without external Composer runtime dependencies', function (): void {
    $root = dirname(__DIR__, 2);
    $composer = json_decode(
        (string) file_get_contents($root.'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $box = json_decode(
        (string) file_get_contents($root.'/box.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $externalRuntimeRequirements = array_filter(
        array_keys($composer['require']),
        static fn (string $package): bool => $package !== 'php' && ! str_starts_with($package, 'ext-'),
    );

    expect($externalRuntimeRequirements)->toBe([])
        ->and($composer['require-dev'])->toHaveKey('laravel-zero/framework')
        ->and($composer['bin'])->toBe(['builds/quickpay'])
        ->and($box['exclude-dev-files'])->toBeFalse();
});

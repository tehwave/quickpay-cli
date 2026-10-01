<?php

use League\Flysystem\CorruptedPathDetected;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\WhitespacePathNormalizer;

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

it('rejects malformed paths before the bundled filesystem dispatches them', function (string $path): void {
    $adapter = Mockery::mock(FilesystemAdapter::class);
    $adapter->allows('fileExists')->andReturn(false);
    $filesystem = new Filesystem($adapter);

    expect(fn () => $filesystem->fileExists($path))->toThrow(CorruptedPathDetected::class);
})->with([
    'malformed UTF-8 and ESC' => "folder/\x80\x1bfile",
    'malformed UTF-8 and newline' => "folder/\xff\nfile",
    'malformed UTF-8 alone' => "folder/\x80file",
]);

it('preserves ordinary path normalization in the bundled filesystem', function (string $path, string $expected): void {
    expect((new WhitespacePathNormalizer)->normalizePath($path))->toBe($expected);
})->with([
    'backslashes and dot segments' => ['folder\\.\\file', 'folder/file'],
    'printable Unicode and spaces' => ['folder/my résumé.txt', 'folder/my résumé.txt'],
    'relative resolution' => ['folder/../file', 'file'],
]);

<?php

it('keeps production namespaces aligned with their directories', function (): void {
    $root = dirname(__DIR__, 2).'/app';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        preg_match('/^namespace\s+([^;]+);/m', (string) $contents, $matches);
        $relativeDirectory = trim(str_replace($root, '', $file->getPath()), DIRECTORY_SEPARATOR);
        $expected = 'App'.($relativeDirectory === '' ? '' : '\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relativeDirectory));

        expect($matches[1] ?? null, $file->getPathname())->toBe($expected);
    }
});

it('has no support grab bag command traits or root-level command classes', function (): void {
    $root = dirname(__DIR__, 2);

    expect(is_dir($root.'/app/Support'))->toBeFalse()
        ->and(is_dir($root.'/app/Commands/Concerns'))->toBeFalse()
        ->and(glob($root.'/app/Commands/*.php'))->toBe([]);
});

it('centralizes process environment access', function (): void {
    $root = dirname(__DIR__, 2).'/app';
    $matches = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if ($file->getExtension() === 'php'
            && str_contains((string) file_get_contents($file->getPathname()), 'getenv(')) {
            $matches[] = $file->getPathname();
        }
    }

    expect($matches)->toBe([$root.'/Credentials/EnvironmentVariables.php']);
});

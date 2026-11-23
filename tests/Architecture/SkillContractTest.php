<?php

it('distributes one discoverable skill whose name matches its directory', function (): void {
    $root = dirname(__DIR__, 2);
    $paths = glob($root.'/skills/*/SKILL.md');
    $names = [];
    foreach ($paths as $path) {
        $contents = file_get_contents($path);
        expect(preg_match('/\A---\nname: ([a-z0-9-]+)\n/', $contents, $matches))->toBe(1);
        $names[] = $matches[1];
        expect(basename(dirname($path)))->toBe($matches[1]);
    }
    expect($names)->toBe(['quickpay-cli']);
});

it('keeps documented skill paths resolvable', function (): void {
    $root = dirname(__DIR__, 2);
    foreach (['README.md', 'AGENTS.md', 'CONTRIBUTING.md'] as $document) {
        preg_match_all('~skills/[a-z-]+/SKILL\.md~', file_get_contents($root.'/'.$document), $matches);
        foreach ($matches[0] as $path) {
            expect(is_file($root.'/'.$path), $document.' references '.$path)->toBeTrue();
        }
    }
});

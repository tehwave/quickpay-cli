<?php

$verifierPath = dirname(__DIR__, 2).'/scripts/verify-phar-source.php';

if (is_file($verifierPath)) {
    require_once $verifierPath;
}

it('ships a focused phar source integrity verifier', function () use ($verifierPath) {
    expect($verifierPath)->toBeFile();
});

it('ignores php whitespace and comments but detects semantic changes', function () {
    $source = <<<'PHP'
<?php

// Source comment.
function answer(): int
{
    return 42;
}
PHP;
    $compacted = '<?php function answer():int{return 42;}';
    $changed = '<?php function answer():int{return 43;}';

    expect(PharSourceIntegrityVerifier::semanticTokenStream($source))
        ->toBe(PharSourceIntegrityVerifier::semanticTokenStream($compacted))
        ->not->toBe(PharSourceIntegrityVerifier::semanticTokenStream($changed));
});

it('reports missing extra and semantically changed app files', function () {
    $issues = PharSourceIntegrityVerifier::compareContents(
        [
            'app/Changed.php' => '<?php return 1;',
            'app/Missing.php' => '<?php return 2;',
        ],
        [
            'app/Changed.php' => '<?php return 3;',
            'app/Extra.php' => '<?php return 2;',
        ],
    );

    expect($issues)->toContain('Missing from PHAR: app/Missing.php')
        ->toContain('Extra in PHAR: app/Extra.php')
        ->toContain('Semantic PHP mismatch: app/Changed.php');
});

it('detects drift across every packaged project and dependency category', function () {
    $issues = PharSourceIntegrityVerifier::compareContents(
        [
            'bootstrap/app.php' => '<?php return 1;',
            'config/commands.php' => '<?php return ["safe"];',
            'vendor/acme/runtime.php' => '<?php return 2;',
            'vendor/acme/LICENSE' => 'expected license',
            'composer.json' => '{"name":"acme/runtime"}',
            'composer.lock' => '{"content-hash":"expected"}',
        ],
        [
            'config/commands.php' => '<?php return ["changed"];',
            'vendor/acme/runtime.php' => '<?php return 3;',
            'vendor/acme/LICENSE' => 'changed license',
            'composer.json' => "{\n  \"name\": \"acme/runtime\"\n}",
            'composer.lock' => '{"content-hash":"changed"}',
            'vendor/acme/extra.php' => '<?php return 4;',
        ],
    );

    expect($issues)->toContain('Missing from PHAR: bootstrap/app.php')
        ->toContain('Extra in PHAR: vendor/acme/extra.php')
        ->toContain('Semantic PHP mismatch: config/commands.php')
        ->toContain('Semantic PHP mismatch: vendor/acme/runtime.php')
        ->toContain('Byte mismatch: vendor/acme/LICENSE')
        ->toContain('Semantic JSON mismatch: composer.lock')
        ->not->toContain('Semantic JSON mismatch: composer.json');
});

it('builds the expected manifest independently from the target phar', function () {
    $projectRoot = dirname(__DIR__, 2);
    $manifest = PharSourceIntegrityVerifier::expectedContents($projectRoot, 'dev');

    expect($manifest)->toHaveKeys([
        'app/Commands/PaymentMutationCommand.php',
        'bootstrap/app.php',
        'config/app.php',
        'quickpay',
        'composer.json',
        'composer.lock',
        'vendor/autoload.php',
        '.box/bin/check-requirements.php',
        '.box/.requirements.php',
    ])->and(count(array_filter(
        array_keys($manifest),
        fn (string $path): bool => str_starts_with($path, 'vendor/'),
    )))->toBeGreaterThan(100);
});

it('requires deterministic box settings for the verified manifest', function () {
    $box = json_decode(file_get_contents(dirname(__DIR__, 2).'/box.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($box['dump-autoload'] ?? null)->toBeFalse();
});

it('matches the committed phar complete runtime to the project and dependency source', function () {
    $projectRoot = dirname(__DIR__, 2);
    $result = PharSourceIntegrityVerifier::verify(
        $projectRoot,
        $projectRoot.'/builds/quickpay',
    );

    expect($result['issues'])->toBe([])
        ->and($result['file_count'])->toBeGreaterThan(7000)
        ->and($result['categories'])->toMatchArray([
            'app' => 31,
            'bootstrap' => 2,
            'config' => 2,
            'launcher' => 2,
            'composer' => 2,
        ])
        ->and($result['categories']['vendor'])->toBeGreaterThan(7000)
        ->and($result['categories']['box-runtime'])->toBeGreaterThan(20);
});

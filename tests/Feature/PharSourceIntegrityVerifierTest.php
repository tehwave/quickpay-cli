<?php

$verifierPath = dirname(__DIR__, 2).'/scripts/verify-phar-source.php';

if (is_file($verifierPath)) {
    require_once $verifierPath;
}

it('ships a focused phar source integrity verifier', function () use ($verifierPath) {
    expect($verifierPath)->toBeFile();
});

it('detects newline drift retained by the box php compactor for line-sensitive code', function () {
    $issues = PharSourceIntegrityVerifier::compareContents(
        ['app/Line.php' => "<?php\n\n\nreturn __LINE__;\n"],
        ['app/Line.php' => "<?php\nreturn __LINE__;\n"],
    );

    expect($issues)->toContain('Byte mismatch: app/Line.php');
});

it('detects retained annotation and reflective docblock drift', function () {
    $issues = PharSourceIntegrityVerifier::compareContents(
        ['app/Annotated.php' => "<?php\n/**\n@Route(\"/safe\")\n*/\nclass Annotated {}\n"],
        ['app/Annotated.php' => "<?php\n/**\n@Route(\"/changed\")\n*/\nclass Annotated {}\n"],
    );

    expect($issues)->toContain('Byte mismatch: app/Annotated.php');
});

it('detects json object versus list drift including nested empty values', function () {
    $issues = PharSourceIntegrityVerifier::compareContents(
        ['composer.json' => '{"top":{},"nested":[{}]}'],
        ['composer.json' => '{"top":[],"nested":[[]]}'],
    );

    expect($issues)->toContain('Byte mismatch: composer.json');
});

it('detects distinct json integers above the 64 bit range', function () {
    $issues = PharSourceIntegrityVerifier::compareContents(
        ['composer.json' => '{"id":9223372036854775808}'],
        ['composer.json' => '{"id":9223372036854775809}'],
    );

    expect($issues)->toContain('Byte mismatch: composer.json');
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
        ->toContain('Byte mismatch: app/Changed.php');
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
        ->toContain('Byte mismatch: config/commands.php')
        ->toContain('Byte mismatch: vendor/acme/runtime.php')
        ->toContain('Byte mismatch: vendor/acme/LICENSE')
        ->toContain('Byte mismatch: composer.lock')
        ->toContain('Byte mismatch: composer.json');
});

it('normalizes only volatile composer root identity and root classmap entries', function () {
    $expectedInstalled = <<<'PHP'
<?php return array(
'root' => array(
'name' => 'peterchrjoergensen/quickpay-cli',
'pretty_version' => 'dev-main',
'version' => 'dev-main',
'reference' => null,
),
'versions' => array(
'peterchrjoergensen/quickpay-cli' => array(
'pretty_version' => 'dev-main',
'version' => 'dev-main',
'reference' => null,
),
),
);
PHP;
    $volatileInstalled = str_replace(
        ["'dev-main'", "'reference' => null"],
        ["'1.2.x-dev'", "'reference' => 'abc123'"],
        $expectedInstalled,
    );
    $expectedClassmap = <<<'PHP'
<?php return array(
'App\\Commands\\ApiCommand' => $baseDir . '/app/Commands/ApiCommand.php',
'Vendor\\Safe' => $vendorDir . '/vendor/safe.php',
);
PHP;
    $volatileClassmap = str_replace(
        "'App\\\\Commands\\\\ApiCommand' => \$baseDir . '/app/Commands/ApiCommand.php',",
        "'App\\\\Commands\\\\OtherCommand' => \$baseDir . '/app/Commands/OtherCommand.php',",
        $expectedClassmap,
    );
    $expectedStatic = <<<'PHP'
<?php class ComposerStaticInitaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa {
'App\\Commands\\ApiCommand' => __DIR__ . '/../..' . '/app/Commands/ApiCommand.php',
'Vendor\\Safe' => __DIR__ . '/..' . '/vendor/safe.php',
}
PHP;
    $volatileStatic = str_replace(
        [str_repeat('a', 32), "'App\\\\Commands\\\\ApiCommand' => __DIR__ . '/../..' . '/app/Commands/ApiCommand.php',"],
        [str_repeat('b', 32), "'App\\\\Commands\\\\OtherCommand' => __DIR__ . '/../..' . '/app/Commands/OtherCommand.php',"],
        $expectedStatic,
    );

    $issues = PharSourceIntegrityVerifier::compareContents(
        [
            'vendor/composer/installed.php' => $expectedInstalled,
            'vendor/composer/autoload_classmap.php' => $expectedClassmap,
            'vendor/composer/autoload_static.php' => $expectedStatic,
        ],
        [
            'vendor/composer/installed.php' => $volatileInstalled,
            'vendor/composer/autoload_classmap.php' => $volatileClassmap,
            'vendor/composer/autoload_static.php' => $volatileStatic,
        ],
    );

    expect($issues)->toBe([]);
});

it('still detects dependency composer metadata reference and autoload drift', function () {
    $expected = [
        'vendor/composer/installed.php' => "<?php return ['versions' => ['vendor/safe' => ['version' => '1.0.0.0', 'reference' => 'abc']]];",
        'vendor/composer/autoload_classmap.php' => "<?php return ['Vendor\\\\Safe' => \$vendorDir . '/vendor/safe.php'];",
        'vendor/composer/autoload_static.php' => '<?php class ComposerStaticInit'.str_repeat('a', 32)." {'Vendor\\\\Safe' => __DIR__ . '/..' . '/vendor/safe.php',}",
    ];
    $changed = [
        'vendor/composer/installed.php' => "<?php return ['versions' => ['vendor/safe' => ['version' => '1.0.1.0', 'reference' => 'def']]];",
        'vendor/composer/autoload_classmap.php' => "<?php return ['Vendor\\\\Safe' => \$vendorDir . '/vendor/changed.php'];",
        'vendor/composer/autoload_static.php' => '<?php class ComposerStaticInit'.str_repeat('b', 32)." {'Vendor\\\\Safe' => __DIR__ . '/..' . '/vendor/changed.php',}",
    ];

    expect(PharSourceIntegrityVerifier::compareContents($expected, $changed))
        ->toContain('Byte mismatch: vendor/composer/installed.php')
        ->toContain('Byte mismatch: vendor/composer/autoload_classmap.php')
        ->toContain('Byte mismatch: vendor/composer/autoload_static.php');
});

it('normalizes composer initializer suffixes and pest plugin ordering only', function () {
    $issues = PharSourceIntegrityVerifier::compareContents(
        [
            'vendor/autoload.php' => '<?php return ComposerAutoloaderInit'.str_repeat('a', 32).'::getLoader();',
            'vendor/pest-plugins.json' => '["Plugin\\\\A","Plugin\\\\B"]',
        ],
        [
            'vendor/autoload.php' => '<?php return ComposerAutoloaderInit'.str_repeat('b', 32).'::getLoader();',
            'vendor/pest-plugins.json' => '["Plugin\\\\B","Plugin\\\\A"]',
        ],
    );

    expect($issues)->toBe([])
        ->and(PharSourceIntegrityVerifier::compareContents(
            ['vendor/pest-plugins.json' => '["Plugin\\\\A","Plugin\\\\B"]'],
            ['vendor/pest-plugins.json' => '["Plugin\\\\A"]'],
        ))->toContain('Byte mismatch: vendor/pest-plugins.json');
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

    expect($box['dump-autoload'] ?? null)->toBeFalse()
        ->and($box['compactors'] ?? null)->toBe([
            'KevinGH\\Box\\Compactor\\Php',
        ]);
});

it('pins composer and a deterministic root identity in continuous integration', function () {
    $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/quality.yml');

    expect($workflow)
        ->toContain("env:\n  COMPOSER_ROOT_VERSION: dev-main")
        ->and(substr_count($workflow, 'tools: composer:2.8.9'))->toBe(4);
});

it('refreshes dependencies with the deterministic root identity before building', function () {
    $composer = json_decode(
        file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($composer['scripts']['build'] ?? null)->toBe([
        'COMPOSER_ROOT_VERSION=dev-main composer install --no-interaction --no-progress --prefer-dist',
        'php quickpay app:build quickpay --build-version=dev --no-interaction',
    ]);
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

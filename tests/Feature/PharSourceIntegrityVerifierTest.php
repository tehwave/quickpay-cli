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

it('matches the committed phar app source to the project app source', function () {
    $projectRoot = dirname(__DIR__, 2);
    $result = PharSourceIntegrityVerifier::verify(
        $projectRoot,
        $projectRoot.'/builds/quickpay',
    );

    expect($result['issues'])->toBe([])
        ->and($result['file_count'])->toBeGreaterThan(0);
});

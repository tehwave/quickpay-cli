<?php

use App\Support\RawApiPath;

it('normalizes allowed relative api paths and decodes their query strings', function (string $input, string $path, array $query) {
    expect(RawApiPath::parse($input))->toBe(['path' => $path, 'query' => $query]);
})->with([
    ['payments', '/payments', []],
    ['/payments', '/payments', []],
    ['/payments/42?operations_size=0&filter[state]=processed', '/payments/42', [
        'operations_size' => '0',
        'filter' => ['state' => 'processed'],
    ]],
]);

it('rejects raw api path attack classes', function (string $path) {
    expect(fn () => RawApiPath::parse($path))->toThrow(InvalidArgumentException::class);
})->with([
    'scheme URL' => 'https://evil.test/payments',
    'scheme without slashes' => 'javascript:payments',
    'protocol-relative host' => '//evil.test/payments',
    'bare host' => 'evil.test/payments',
    'quickpay bare host' => 'api.quickpay.net/payments',
    'userinfo' => 'attacker@evil.test/payments',
    'userinfo with password' => 'attacker:secret@evil.test/payments',
    'backslash' => 'payments\\..\\secrets',
    'encoded backslash' => 'payments/%5c/secrets',
    'nul' => "payments/\0secret",
    'control' => "payments/\nsecret",
    'encoded control' => 'payments/%00secret',
    'fragment' => 'payments#fragment',
    'encoded fragment' => 'payments/%23fragment',
    'bare percent' => 'payments/%',
    'short percent' => 'payments/%2',
    'non-hex percent' => 'payments/%GG',
    'decoded malformed percent' => 'payments/%252G',
    'dot dot segment' => 'payments/../42',
    'encoded dot dot segment' => 'payments/%2e%2e/42',
    'twice encoded dot dot segment' => 'payments/%252e%252e/42',
    'three times encoded traversal' => 'payments/%25252e%25252e%25252fsecret',
    'encoded scheme' => 'https%3A%2F%2Fevil.test/payments',
    'encoded protocol-relative host' => '%2F%2Fevil.test/payments',
]);

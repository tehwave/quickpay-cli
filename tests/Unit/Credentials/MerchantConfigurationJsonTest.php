<?php

use App\Credentials\MerchantConfigurationJson;

it('distinguishes separate object scopes and quoted property-like values', function (): void {
    $decoded = MerchantConfigurationJson::decode('{"left":{"id":1},"right":{"id":2},"text":"{\"id\":1,\"id\":2}"}');
    expect($decoded->left->id)->toBe(1)->and($decoded->right->id)->toBe(2)
        ->and($decoded->text)->toBe('{"id":1,"id":2}');
});

it('rejects equivalent duplicate properties within an object', function (string $json): void {
    expect(fn () => MerchantConfigurationJson::decode($json))->toThrow(JsonException::class);
})->with([
    'plain' => '{"merchant":"shop-a","merchant":"shop-b"}',
    'escaped' => '{"merchant":"shop-a","merch\\u0061nt":"shop-b"}',
    'nested in array' => '[{"id":1,"id":2}]',
    'after a long value' => '{"merchant":"'.str_repeat('a', 20000).'","merchant":"shop-b"}',
    'after a long escaped value' => '{"merchant":"'.str_repeat('a\\"', 20000).'","merchant":"shop-b"}',
]);

it('rejects configuration it cannot scan for duplicate properties', function (): void {
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '1');
    try {
        expect(fn () => MerchantConfigurationJson::decode('{"merchant":"'.str_repeat('a\\"', 1000).'"}'))
            ->toThrow(JsonException::class);
    } finally {
        ini_set('pcre.backtrack_limit', (string) $limit);
    }
});

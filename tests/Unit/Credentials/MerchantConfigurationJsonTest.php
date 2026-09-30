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
]);

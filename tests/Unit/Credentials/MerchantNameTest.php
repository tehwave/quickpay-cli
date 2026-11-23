<?php

use App\Credentials\Exceptions\CredentialException;
use App\Credentials\MerchantName;

it('accepts exact bounded merchant names', function (string $name): void {
    expect(MerchantName::validate($name))->toBe($name);
})->with([
    'one letter' => 'a',
    'maximum' => str_repeat('a', 64),
    'shop' => 'shop-a1',
    'uppercase' => 'Shop-A',
    'readable name' => 'My Website',
    'hyphenated name' => 'My Website-2',
    'cancel label' => 'Cancel',
]);

it('finds a saved name that differs only in capitalization', function (string $name, array $saved, ?string $variant): void {
    expect(MerchantName::caseVariant($name, $saved))->toBe($variant);
})->with([
    'case variant' => ['my website', ['My Website', 'shop-b'], 'My Website'],
    'exact name is not a variant' => ['My Website', ['My Website'], null],
    'different name' => ['shop-c', ['shop-a', 'shop-b'], null],
    'empty store' => ['shop-a', [], null],
]);

it('rejects unsafe merchant names without echoing input', function (string $name): void {
    expect(fn () => MerchantName::validate($name))->toThrow(CredentialException::class);
})->with([
    'empty' => '', 'too long' => str_repeat('a', 65),
    'leading space' => ' My Website', 'trailing space' => 'My Website ', 'double space' => 'My  Website',
    'numeric' => '12345', 'leading hyphen' => '-shop', 'trailing hyphen' => 'shop-',
    'double hyphen' => 'shop--a', 'traversal' => '../shop', 'slash' => 'shop/a',
    'backslash' => 'shop\\a', 'newline' => "shop\na", 'escape' => "shop\x1ba",
]);

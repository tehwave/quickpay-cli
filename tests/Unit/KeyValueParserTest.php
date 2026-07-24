<?php

use App\Support\KeyValueParser;

it('splits fields on the first equals sign', function () {
    expect(KeyValueParser::parse(['description=reference=a=b']))
        ->toBe(['description' => 'reference=a=b']);
});

it('builds nested arrays from bracket notation', function () {
    expect(KeyValueParser::parse([
        'invoice_address[email]=a@example.com',
        'basket[0][qty]=2',
        'basket[0][name]=Coffee',
    ]))->toBe([
        'invoice_address' => ['email' => 'a@example.com'],
        'basket' => [['qty' => '2', 'name' => 'Coffee']],
    ]);
});

it('lets a later field override the same scalar key', function () {
    expect(KeyValueParser::parse(['description=old', 'description=new']))
        ->toBe(['description' => 'new']);
});

it('rejects fields without a non-empty key and equals separator', function (string $field) {
    expect(fn () => KeyValueParser::parse([$field]))
        ->toThrow(InvalidArgumentException::class, 'key=value');
})->with(['missing-separator', '=value', '   =value']);

it('rejects malformed bracket notation', function (string $field) {
    expect(fn () => KeyValueParser::parse([$field]))
        ->toThrow(InvalidArgumentException::class, 'Malformed field key');
})->with(['basket[]=2', 'basket[0=2', 'basket[0]qty=2']);

it('rejects conflicting scalar and nested field structures', function (array $fields) {
    expect(fn () => KeyValueParser::parse($fields))
        ->toThrow(InvalidArgumentException::class, 'conflicts');
})->with([
    [['basket=value', 'basket[0]=item']],
    [['basket[0]=item', 'basket=value']],
    [['basket[0]=item', 'basket[0][qty]=2']],
]);

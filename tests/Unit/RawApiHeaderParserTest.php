<?php

use App\Support\RawApiHeaderParser;

it('parses safe raw api headers on the first colon', function () {
    expect(RawApiHeaderParser::parse([
        'Idempotency-Key:operation:42',
        'QuickPay-Callback-Url:https://merchant.test/callback',
    ]))->toBe([
        'Idempotency-Key' => 'operation:42',
        'QuickPay-Callback-Url' => 'https://merchant.test/callback',
    ]);
});

it('rejects case-insensitive protected raw api headers', function (string $header) {
    expect(fn () => RawApiHeaderParser::parse([$header.':override']))
        ->toThrow(InvalidArgumentException::class, 'cannot be overridden');
})->with(['Authorization', 'aUtHoRiZaTiOn', 'HOST', 'Accept-Version', 'aCcEpT-vErSiOn']);

it('rejects malformed header names and control characters', function (string $header) {
    expect(fn () => RawApiHeaderParser::parse([$header]))->toThrow(InvalidArgumentException::class);
})->with([
    'missing colon' => 'X-Test',
    'empty name' => ':value',
    'space in name' => 'Bad Name:value',
    'control in name' => "Bad\nName:value",
    'control in value' => "X-Test:value\r\nInjected: yes",
    'leading control in name' => "\tX-Test:value",
    'trailing control in value' => "X-Test:value\r",
]);

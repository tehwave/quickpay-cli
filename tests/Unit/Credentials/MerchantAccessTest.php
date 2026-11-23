<?php

use App\Credentials\MerchantAccess;

it('always allows GET and blocks every other method for a read-only merchant', function (bool $readOnly, string $method, bool $allowed): void {
    expect((new MerchantAccess($readOnly))->allows($method))->toBe($allowed);
})->with([
    'read-only GET' => [true, 'GET', true],
    'read-only lowercase get' => [true, 'get', true],
    'read-only POST' => [true, 'POST', false],
    'read-only PUT' => [true, 'put', false],
    'read-only PATCH' => [true, 'PATCH', false],
    'read-only DELETE' => [true, 'DELETE', false],
    'read-only unknown method' => [true, 'OPTIONS', false],
    'full GET' => [false, 'GET', true],
    'full PUT' => [false, 'PUT', true],
    'full DELETE' => [false, 'DELETE', true],
]);

it('names the access', function (bool $readOnly, string $label): void {
    expect((new MerchantAccess($readOnly))->label())->toBe($label);
})->with([
    'full' => [false, 'read and write'],
    'read-only' => [true, 'read-only'],
]);

it('grants full access through the named constructor', function (): void {
    expect(MerchantAccess::full()->readOnly)->toBeFalse();
});

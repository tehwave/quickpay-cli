<?php

use App\Credentials\MerchantAccess;

it('always allows GET and blocks every other method for a read-only merchant', function (bool $write, string $method, bool $allowed): void {
    expect((new MerchantAccess($write))->allows($method))->toBe($allowed);
})->with([
    'read-only GET' => [false, 'GET', true],
    'read-only lowercase get' => [false, 'get', true],
    'read-only POST' => [false, 'POST', false],
    'read-only PUT' => [false, 'put', false],
    'read-only PATCH' => [false, 'PATCH', false],
    'read-only DELETE' => [false, 'DELETE', false],
    'read-only unknown method' => [false, 'OPTIONS', false],
    'full GET' => [true, 'GET', true],
    'full PUT' => [true, 'PUT', true],
    'full DELETE' => [true, 'DELETE', true],
]);

it('names the access', function (bool $write, string $label): void {
    expect((new MerchantAccess($write))->label())->toBe($label);
})->with([
    'full' => [true, 'read and write'],
    'read-only' => [false, 'read-only'],
]);

it('grants full access through the named constructor', function (): void {
    expect(MerchantAccess::full()->write)->toBeTrue();
});

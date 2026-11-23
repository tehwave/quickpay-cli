<?php

use App\Credentials\MerchantAccess;

it('treats GET as a read and every other method as a write', function (bool $read, bool $write, string $method, bool $allowed): void {
    expect((new MerchantAccess($read, $write))->allows($method))->toBe($allowed);
})->with([
    'read-only GET' => [true, false, 'GET', true],
    'read-only lowercase get' => [true, false, 'get', true],
    'read-only POST' => [true, false, 'POST', false],
    'read-only PUT' => [true, false, 'put', false],
    'read-only PATCH' => [true, false, 'PATCH', false],
    'read-only DELETE' => [true, false, 'DELETE', false],
    'read-only unknown method' => [true, false, 'OPTIONS', false],
    'write-only GET' => [false, true, 'GET', false],
    'write-only POST' => [false, true, 'POST', true],
    'write-only DELETE' => [false, true, 'DELETE', true],
    'no access GET' => [false, false, 'GET', false],
    'no access POST' => [false, false, 'POST', false],
    'full GET' => [true, true, 'GET', true],
    'full PUT' => [true, true, 'PUT', true],
]);

it('names each access combination', function (bool $read, bool $write, string $label, bool $full): void {
    $access = new MerchantAccess($read, $write);
    expect($access->label())->toBe($label)->and($access->isFull())->toBe($full);
})->with([
    'full' => [true, true, 'read and write', true],
    'read-only' => [true, false, 'read-only', false],
    'write-only' => [false, true, 'write-only', false],
    'none' => [false, false, 'reads and writes blocked', false],
]);

it('grants full access by default only through the named constructor', function (): void {
    $access = MerchantAccess::full();
    expect([$access->read, $access->write])->toBe([true, true]);
});

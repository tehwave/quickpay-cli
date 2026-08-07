<?php

use App\Console\Input\IntegerInput;

it('parses positive integers without coercing other values', function (mixed $value, int $expected): void {
    expect(IntegerInput::positive($value, 'amount'))->toBe($expected);
})->with([
    ['1', 1],
    [42, 42],
]);

it('rejects invalid positive integers', function (mixed $value): void {
    expect(fn (): int => IntegerInput::positive($value, 'amount'))
        ->toThrow(InvalidArgumentException::class, 'amount must be a positive integer.');
})->with([0, '0', '-1', '1.0', '01', 1.0, null]);

it('parses non-negative integers', function (mixed $value, int $expected): void {
    expect(IntegerInput::nonNegative($value, 'operations-size'))->toBe($expected);
})->with([
    ['0', 0],
    [0, 0],
    ['42', 42],
]);

it('rejects invalid non-negative integers', function (mixed $value): void {
    expect(fn (): int => IntegerInput::nonNegative($value, 'operations-size'))
        ->toThrow(InvalidArgumentException::class, 'operations-size must be a non-negative integer.');
})->with(['-1', '1.0', '01', 1.0, null]);

it('parses non-negative numbers', function (mixed $value, int|float $expected): void {
    expect(IntegerInput::nonNegativeNumber($value, 'vat-rate'))->toBe($expected);
})->with([
    ['0', 0],
    ['0.25', 0.25],
    ['.5', 0.5],
    [2, 2],
    [2.5, 2.5],
]);

it('rejects invalid non-negative numbers', function (mixed $value): void {
    expect(fn (): int|float => IntegerInput::nonNegativeNumber($value, 'vat-rate'))
        ->toThrow(InvalidArgumentException::class, 'vat-rate must be a non-negative number.');
})->with(['-0.1', '1e3', 'not-a-number', INF, null]);

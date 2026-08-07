<?php

use App\Quickpay\Payments\PaymentResponse;
use App\Quickpay\QuickpayResponse;

it('requires object-shaped payment responses', function (string $body): void {
    $response = new QuickpayResponse(200, [], $body, json_decode($body, true));

    expect(fn (): array => PaymentResponse::object($response, 'invalid payment'))
        ->toThrow(InvalidArgumentException::class, 'invalid payment');
})->with(['[]', 'null', '"value"', '{malformed']);

it('requires list-shaped payment responses', function (string $body): void {
    $response = new QuickpayResponse(200, [], $body, json_decode($body, true));

    expect(fn (): array => PaymentResponse::list($response, 'invalid list'))
        ->toThrow(InvalidArgumentException::class, 'invalid list');
})->with(['{}', 'null', '"value"', '{malformed']);

it('accepts empty objects and lists', function (): void {
    expect(PaymentResponse::object(new QuickpayResponse(200, [], '{}', []), 'invalid'))->toBe([])
        ->and(PaymentResponse::list(new QuickpayResponse(200, [], '[]', []), 'invalid'))->toBe([]);
});

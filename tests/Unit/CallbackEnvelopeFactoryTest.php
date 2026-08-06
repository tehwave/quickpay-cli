<?php

use App\Callbacks\CallbackEnvelopeFactory;
use App\Quickpay\QuickpayResponse;

it('signs the exact sanitized payment bytes and builds only Quickpay callback headers', function () {
    $apiKey = 'api-secret';
    $privateKey = 'private-signing-key';
    $raw = " {\n  \"id\": 42,\n  \"order_id\": \"order-api-secret\",\n  \"merchant_id\": 123,\n  \"operations\": []\n}\n";
    $response = new QuickpayResponse(200, [], $raw, json_decode($raw, true));

    $envelope = (new CallbackEnvelopeFactory)->make($response, $apiKey, $privateKey);

    expect($envelope->body)->toBe('{"id":42,"order_id":"order-[redacted]","merchant_id":123,"operations":[]}')
        ->and($envelope->headers)->toBe([
            'Content-Type' => 'application/json',
            'QuickPay-Resource-Type' => 'Payment',
            'QuickPay-Account-ID' => '123',
            'QuickPay-API-Version' => 'v10',
            'QuickPay-Checksum-Sha256' => hash_hmac('sha256', $envelope->body, $privateKey),
        ])
        ->and($envelope->body)->not->toContain($apiKey)
        ->and(implode(' ', $envelope->headers))->not->toContain($privateKey, $apiKey);
});

it('rejects malformed payment resources before forwarding them', function (string $raw, string $message) {
    $response = new QuickpayResponse(200, [], $raw, json_decode($raw, true));

    expect(fn () => (new CallbackEnvelopeFactory)->make($response, 'api-key', 'private-key'))
        ->toThrow(UnexpectedValueException::class, $message);
})->with([
    'not an object' => ['[]', 'payment object'],
    'missing payment id' => ['{"merchant_id":123,"order_id":"order-1"}', 'payment ID'],
    'missing merchant id' => ['{"id":42,"order_id":"order-1"}', 'merchant ID'],
]);

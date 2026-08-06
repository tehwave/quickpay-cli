<?php

use App\Callbacks\CallbackPollingException;
use App\Callbacks\PaymentLocator;
use App\Quickpay\QuickpayClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('fetches a fixed payment by id', function () {
    Http::fake(['https://api.quickpay.net/payments/42' => Http::response([
        'id' => 42,
        'order_id' => 'order-42',
        'merchant_id' => 123,
    ])]);

    $locator = new PaymentLocator(new QuickpayClient(app(Factory::class), 'api-key'));
    $payment = $locator->byId('42');

    expect($payment->json['id'])->toBe(42);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.quickpay.net/payments/42');
});

it('resolves an exact order match with a bounded lookup and then fetches its current resource', function () {
    Http::fake([
        'https://api.quickpay.net/payments?*' => Http::response([
            ['id' => 42, 'order_id' => 'order-42'],
        ]),
        'https://api.quickpay.net/payments/42' => Http::response([
            'id' => 42,
            'order_id' => 'order-42',
            'merchant_id' => 123,
        ]),
    ]);

    $locator = new PaymentLocator(new QuickpayClient(app(Factory::class), 'api-key'));
    $payment = $locator->byOrderId('order-42');

    expect($payment?->json['id'])->toBe(42);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.quickpay.net/payments?order_id=order-42&page_size=2');
    Http::assertSentCount(2);
});

it('returns null for a missing order but rejects ambiguous or malformed lookup responses', function (array $response, ?string $message) {
    Http::fake(['https://api.quickpay.net/payments?*' => Http::response($response)]);

    $locator = new PaymentLocator(new QuickpayClient(app(Factory::class), 'api-key'));

    if ($message === null) {
        expect($locator->byOrderId('order-42'))->toBeNull();

        return;
    }

    expect(fn () => $locator->byOrderId('order-42'))
        ->toThrow(UnexpectedValueException::class, $message);
})->with([
    'missing' => [[], null],
    'ambiguous' => [[
        ['id' => 42, 'order_id' => 'order-42'],
        ['id' => 43, 'order_id' => 'order-42'],
    ], 'multiple payments'],
    'malformed item' => [[['order_id' => 'order-42']], 'payment ID'],
]);

it('classifies retryable polling responses and keeps authentication or missing-payment failures fatal', function (int $status, bool $retryable, ?int $retryAfter) {
    Http::fake(['https://api.quickpay.net/payments/42' => Http::response(
        ['message' => 'request failed'],
        $status,
        $retryAfter === null ? [] : ['Retry-After' => (string) $retryAfter],
    )]);

    $locator = new PaymentLocator(new QuickpayClient(app(Factory::class), 'api-key'));

    try {
        $locator->byId('42');
        $this->fail('Expected callback polling exception.');
    } catch (CallbackPollingException $exception) {
        expect($exception->retryable)->toBe($retryable)
            ->and($exception->retryAfter)->toBe($retryAfter)
            ->and($exception->status)->toBe($status);
    }
})->with([
    'rate limited' => [429, true, 7],
    'server error' => [503, true, null],
    'missing fixed payment' => [404, false, null],
    'authentication error' => [401, false, null],
]);

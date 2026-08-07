<?php

use App\Quickpay\Payments\PaymentApi;
use App\Quickpay\Payments\PaymentMutation;
use App\Quickpay\Payments\PaymentPaginator;
use App\Quickpay\QuickpayClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('paginates through validated relative next targets', function (): void {
    Http::fake([
        'https://api.quickpay.net/payments?page_size=1' => Http::response(
            [['id' => 1]],
            200,
            ['Link' => '<https://api.quickpay.net/payments?page=2&page_size=1>; rel="next"'],
        ),
        'https://api.quickpay.net/payments?page=2&page_size=1' => Http::response([['id' => 2]]),
    ]);

    $api = new PaymentApi(new QuickpayClient(app(Factory::class), 'api-key'));

    expect((new PaymentPaginator($api))->all(['page_size' => 1], 2))
        ->toBe([['id' => 1], ['id' => 2]]);
    Http::assertSentCount(2);
});

it('fetches context before confirmation and never mutates when declined', function (): void {
    Http::fake(['https://api.quickpay.net/payments/42' => Http::response(['id' => 42])]);
    $api = new PaymentApi(new QuickpayClient(app(Factory::class), 'api-key'));

    $result = (new PaymentMutation($api))->execute(
        id: 42,
        operation: 'capture',
        body: ['amount' => 100],
        query: [],
        headers: [],
        confirm: fn (array $payment): bool => false,
    );

    expect($result)->toBeNull();
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
});

it('mutates exactly once after context is approved', function (): void {
    Http::fake([
        'https://api.quickpay.net/payments/42' => Http::response(['id' => 42]),
        'https://api.quickpay.net/payments/42/capture' => Http::response(['id' => 42, 'state' => 'processed']),
    ]);
    $api = new PaymentApi(new QuickpayClient(app(Factory::class), 'api-key'));

    $result = (new PaymentMutation($api))->execute(
        id: 42,
        operation: 'capture',
        body: ['amount' => 100],
        query: [],
        headers: [],
        confirm: fn (array $payment): bool => $payment['id'] === 42,
    );

    expect($result?->data['state'])->toBe('processed');
    Http::assertSentCount(2);
});

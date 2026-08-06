<?php

use App\Callbacks\CallbackEnvelopeFactory;
use App\Callbacks\CallbackForwarder;
use App\Callbacks\CallbackTarget;
use App\Callbacks\CallbackWatcherFactory;
use App\Callbacks\CallbackWatchRunner;
use App\Callbacks\PaymentLocator;
use App\Quickpay\QuickpayClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('baselines existing operations and forwards every later operation in order', function () {
    $paymentResponses = Http::sequence()
        ->push(watchPayment([watchOperation(1, 'authorize', '2026-07-25T10:00:00Z')]))
        ->push(watchPayment([
            watchOperation(3, 'capture', '2026-07-25T10:02:00Z'),
            watchOperation(1, 'authorize', '2026-07-25T10:00:00Z'),
            watchOperation(2, 'capture', '2026-07-25T10:01:00Z'),
        ]));
    Http::fake([
        'https://api.quickpay.net/payments/42' => $paymentResponses,
        'http://localhost/callback' => Http::sequence()->push('', 204)->push('', 204),
    ]);
    $events = [];
    $sleeps = [];
    $runner = callbackWatchRunner($sleeps, 1);

    $runner->run(
        paymentId: '42',
        orderId: null,
        target: CallbackTarget::fromString('http://localhost/callback'),
        apiKey: 'api-key',
        privateKey: 'private-key',
        interval: 2,
        observer: function (string $event, array $context) use (&$events): void {
            $events[] = [$event, $context];
        },
    );

    $localRequests = collect(Http::recorded())
        ->map(fn (array $record): Request => $record[0])
        ->filter(fn (Request $request): bool => $request->url() === 'http://localhost/callback')
        ->values();

    expect($localRequests)->toHaveCount(2)
        ->and($localRequests[0]->body())->toBe($localRequests[1]->body())
        ->and($events)->toContain(['multiple-operations', ['count' => 2]])
        ->and(array_column(array_column($events, 1), 'operation_id'))->toContain('2', '3')
        ->and($sleeps)->toBe([2]);
});

it('treats all operations as new when an order appears after watching starts', function () {
    Http::fake([
        'https://api.quickpay.net/payments?*' => Http::sequence()
            ->push([])
            ->push([['id' => 42, 'order_id' => 'new-order']]),
        'https://api.quickpay.net/payments/42' => Http::response(watchPayment([
            watchOperation(1, 'authorize', '2026-07-25T10:00:00Z'),
        ], 'new-order')),
        'http://localhost/callback' => Http::response('', 204),
    ]);
    $sleeps = [];
    $runner = callbackWatchRunner($sleeps, 1);
    $events = [];

    $runner->run(
        paymentId: null,
        orderId: 'new-order',
        target: CallbackTarget::fromString('http://localhost/callback'),
        apiKey: 'api-key',
        privateKey: 'private-key',
        interval: 3,
        observer: function (string $event, array $context) use (&$events): void {
            $events[] = [$event, $context];
        },
    );

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost/callback');
    expect($events)->toContain(['waiting-for-payment', ['order_id' => 'new-order']])
        ->and(array_column(array_column($events, 1), 'operation_id'))->toContain('1')
        ->and($sleeps)->toBe([3]);
});

it('retries an unchanged captured envelope before delivering later operations', function () {
    Http::fake([
        'https://api.quickpay.net/payments/42' => Http::sequence()
            ->push(watchPayment([]))
            ->push(watchPayment([
                watchOperation(1, 'authorize', '2026-07-25T10:00:00Z'),
                watchOperation(2, 'capture', '2026-07-25T10:01:00Z'),
            ])),
        'http://localhost/callback' => Http::sequence()
            ->push('temporary local failure', 500)
            ->push('', 204)
            ->push('', 204),
    ]);
    $sleeps = [];
    $runner = callbackWatchRunner($sleeps, 1);

    $runner->run(
        paymentId: '42',
        orderId: null,
        target: CallbackTarget::fromString('http://localhost/callback'),
        apiKey: 'api-key',
        privateKey: 'private-key',
        interval: 2,
        observer: fn (string $event, array $context): null => null,
    );

    $requests = collect(Http::recorded())
        ->map(fn (array $record): Request => $record[0])
        ->filter(fn (Request $request): bool => $request->url() === 'http://localhost/callback')
        ->values();

    expect($requests)->toHaveCount(3)
        ->and($requests[0]->body())->toBe($requests[1]->body())
        ->and($requests[1]->body())->toBe($requests[2]->body())
        ->and($requests[0]->header('QuickPay-Checksum-Sha256'))
        ->toBe($requests[1]->header('QuickPay-Checksum-Sha256'))
        ->and($requests[1]->header('QuickPay-Checksum-Sha256'))
        ->toBe($requests[2]->header('QuickPay-Checksum-Sha256'))
        ->and($sleeps)->toBe([2, 2]);
});

it('honors a numeric retry-after while polling Quickpay', function () {
    Http::fake([
        'https://api.quickpay.net/payments/42' => Http::sequence()
            ->push(['message' => 'slow down'], 429, ['Retry-After' => '7'])
            ->push(watchPayment([])),
    ]);
    $sleeps = [];
    $events = [];
    $runner = callbackWatchRunner($sleeps, 0);

    $runner->run(
        paymentId: '42',
        orderId: null,
        target: CallbackTarget::fromString('http://localhost/callback'),
        apiKey: 'api-key',
        privateKey: 'private-key',
        interval: 2,
        observer: function (string $event, array $context) use (&$events): void {
            $events[] = [$event, $context];
        },
    );

    expect($sleeps)->toBe([7])
        ->and($events)->toContain(['polling-retry', ['status' => 429, 'delay' => 7]]);
});

it('assembles the production watcher from the credential-scoped clients', function () {
    $watcher = (new CallbackWatcherFactory)->make(
        new QuickpayClient(app(Factory::class), 'api-key'),
        app(Factory::class),
    );

    expect($watcher)->toBeInstanceOf(CallbackWatchRunner::class);
});

/** @param array<int, array<string, mixed>> $operations */
function watchPayment(array $operations, string $orderId = 'order-42'): array
{
    return [
        'id' => 42,
        'order_id' => $orderId,
        'merchant_id' => 123,
        'operations' => $operations,
    ];
}

/** @return array<string, mixed> */
function watchOperation(int $id, string $type, string $createdAt): array
{
    return ['id' => $id, 'type' => $type, 'created_at' => $createdAt];
}

/** @param array<int, int> $sleeps */
function callbackWatchRunner(array &$sleeps, int $polls): CallbackWatchRunner
{
    $remaining = $polls;

    return new CallbackWatchRunner(
        locator: new PaymentLocator(new QuickpayClient(app(Factory::class), 'api-key')),
        envelopes: new CallbackEnvelopeFactory,
        forwarder: new CallbackForwarder(app(Factory::class)),
        sleep: function (int $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        },
        continue: function () use (&$remaining): bool {
            return $remaining-- > 0;
        },
    );
}

<?php

use App\Callbacks\Delivery\CallbackForwarder;
use App\Callbacks\Delivery\CallbackTarget;
use App\Callbacks\Resolution\PaymentLocator;
use App\Callbacks\Signing\CallbackEnvelopeFactory;
use App\Callbacks\Watching\CallbackWatcherFactory;
use App\Callbacks\Watching\CallbackWatchRunner;
use App\Quickpay\QuickpayClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('starts account-wide watching at the next whole UTC second and scans an overlapping closed window', function () {
    Http::fake(['https://api.quickpay.net/payments?*' => Http::response([])]);
    $sleeps = [];
    $waitedUntil = [];
    $clockValues = [
        new DateTimeImmutable('2026-08-07T12:00:00.250000+02:00'),
        new DateTimeImmutable('2026-08-07T10:00:03.900000Z'),
    ];
    $events = [];
    $runner = callbackWatchRunner(
        sleeps: $sleeps,
        polls: 1,
        clock: function () use (&$clockValues): DateTimeImmutable {
            return array_shift($clockValues);
        },
        waitUntil: function (DateTimeImmutable $time) use (&$waitedUntil): void {
            $waitedUntil[] = $time->format('Y-m-d\TH:i:s.uP');
        },
    );

    $runner->run(
        paymentId: null,
        orderId: null,
        target: CallbackTarget::fromString('http://localhost/callback'),
        apiKey: 'api-key',
        privateKey: 'private-key',
        interval: 2,
        observer: function (string $event, array $context) use (&$events): void {
            $events[] = [$event, $context];
        },
    );

    expect($waitedUntil)->toBe(['2026-08-07T10:00:01.000000+00:00'])
        ->and($events)->toContain(['watching-all', ['ready_at' => '2026-08-07T10:00:01+00:00']])
        ->and($sleeps)->toBe([2]);

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query['min_time'] === '2026-08-07 10:00:00 +0000'
            && $query['max_time'] === '2026-08-07 10:00:03 +0000';
    });
});

it('forwards only post-readiness operations across payments without colliding operation ids', function () {
    Http::fake([
        'https://api.quickpay.net/payments?*' => Http::response([['id' => 42], ['id' => 43]]),
        'https://api.quickpay.net/payments/42' => Http::response(watchPayment([
            watchOperation(2, 'capture', '2026-08-07T10:00:03Z'),
            watchOperation(9, 'authorize', '2026-08-07T10:00:00Z'),
            watchOperation(10, 'authorize', '2026-08-07T10:00:01Z'),
            watchOperation(1, 'authorize', '2026-08-07T10:00:02Z'),
        ])),
        'https://api.quickpay.net/payments/43' => Http::response([
            ...watchPayment([watchOperation(1, 'authorize', '2026-08-07T10:00:02Z')], 'order-43'),
            'id' => 43,
        ]),
        'http://localhost/callback' => Http::sequence()
            ->push('', 204)
            ->push('', 204)
            ->push('', 204)
            ->push('', 204),
    ]);
    $sleeps = [];
    $clockValues = [
        new DateTimeImmutable('2026-08-07T10:00:00.500000Z'),
        new DateTimeImmutable('2026-08-07T10:00:04.500000Z'),
    ];
    $events = [];
    $runner = callbackWatchRunner(
        sleeps: $sleeps,
        polls: 1,
        clock: function () use (&$clockValues): DateTimeImmutable {
            return array_shift($clockValues);
        },
        waitUntil: fn (DateTimeImmutable $time): null => null,
    );

    $runner->run(
        paymentId: null,
        orderId: null,
        target: CallbackTarget::fromString('http://localhost/callback'),
        apiKey: 'api-key',
        privateKey: 'private-key',
        interval: 2,
        observer: function (string $event, array $context) use (&$events): void {
            $events[] = [$event, $context];
        },
    );

    $callbackPaymentIds = collect(Http::recorded())
        ->map(fn (array $record): Request => $record[0])
        ->filter(fn (Request $request): bool => $request->url() === 'http://localhost/callback')
        ->map(fn (Request $request): mixed => json_decode($request->body(), true)['id'])
        ->values()
        ->all();
    $delivered = collect($events)
        ->filter(fn (array $event): bool => $event[0] === 'delivered')
        ->map(fn (array $event): array => [
            $event[1]['payment_id'],
            $event[1]['operation_id'],
        ])
        ->values()
        ->all();

    expect($callbackPaymentIds)->toBe([42, 42, 42, 43])
        ->and($delivered)->toBe([
            ['42', '10'],
            ['42', '1'],
            ['42', '2'],
            ['43', '1'],
        ]);
});

it('overlaps scan windows by one second without redelivering known payment operations', function () {
    Http::fake([
        'https://api.quickpay.net/payments?*' => Http::sequence()
            ->push([['id' => 42]])
            ->push([['id' => 42]]),
        'https://api.quickpay.net/payments/42' => Http::sequence()
            ->push(watchPayment([watchOperation(1, 'authorize', '2026-08-07T10:00:02Z')]))
            ->push(watchPayment([
                watchOperation(1, 'authorize', '2026-08-07T10:00:02Z'),
                watchOperation(2, 'capture', '2026-08-07T10:00:04Z'),
            ])),
        'http://localhost/callback' => Http::sequence()->push('', 204)->push('', 204),
    ]);
    $sleeps = [];
    $clockValues = [
        new DateTimeImmutable('2026-08-07T10:00:00.500000Z'),
        new DateTimeImmutable('2026-08-07T10:00:03.500000Z'),
        new DateTimeImmutable('2026-08-07T10:00:05.500000Z'),
    ];
    $events = [];
    $runner = callbackWatchRunner(
        sleeps: $sleeps,
        polls: 2,
        clock: function () use (&$clockValues): DateTimeImmutable {
            return array_shift($clockValues);
        },
        waitUntil: fn (DateTimeImmutable $time): null => null,
    );

    $runner->run(
        paymentId: null,
        orderId: null,
        target: CallbackTarget::fromString('http://localhost/callback'),
        apiKey: 'api-key',
        privateKey: 'private-key',
        interval: 2,
        observer: function (string $event, array $context) use (&$events): void {
            $events[] = [$event, $context];
        },
    );

    $delivered = collect($events)
        ->filter(fn (array $event): bool => $event[0] === 'delivered')
        ->pluck('1.operation_id')
        ->values()
        ->all();
    $windows = collect(Http::recorded())
        ->map(fn (array $record): Request => $record[0])
        ->filter(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/payments')
        ->map(function (Request $request): array {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return [$query['min_time'], $query['max_time']];
        })
        ->values()
        ->all();

    expect($delivered)->toBe(['1', '2'])
        ->and($windows)->toBe([
            ['2026-08-07 10:00:00 +0000', '2026-08-07 10:00:03 +0000'],
            ['2026-08-07 10:00:02 +0000', '2026-08-07 10:00:05 +0000'],
        ]);
});

it('retries a failed account-wide scan without advancing its watermark', function () {
    Http::fake([
        'https://api.quickpay.net/payments?*' => Http::sequence()
            ->push(['message' => 'slow down'], 429, ['Retry-After' => '7'])
            ->push([]),
    ]);
    $sleeps = [];
    $clockValues = [
        new DateTimeImmutable('2026-08-07T10:00:00.500000Z'),
        new DateTimeImmutable('2026-08-07T10:00:03.500000Z'),
    ];
    $events = [];
    $runner = callbackWatchRunner(
        sleeps: $sleeps,
        polls: 1,
        clock: function () use (&$clockValues): DateTimeImmutable {
            return array_shift($clockValues);
        },
        waitUntil: fn (DateTimeImmutable $time): null => null,
    );

    $runner->run(
        paymentId: null,
        orderId: null,
        target: CallbackTarget::fromString('http://localhost/callback'),
        apiKey: 'api-key',
        privateKey: 'private-key',
        interval: 2,
        observer: function (string $event, array $context) use (&$events): void {
            $events[] = [$event, $context];
        },
    );

    $scanUrls = collect(Http::recorded())
        ->map(fn (array $record): Request => $record[0])
        ->filter(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/payments')
        ->pluck('url')
        ->values()
        ->all();

    expect($scanUrls)->toHaveCount(2)
        ->and($scanUrls[0])->toBe($scanUrls[1])
        ->and($sleeps)->toBe([2, 7])
        ->and($events)->toContain(['polling-retry', ['status' => 429, 'delay' => 7]]);
});

it('retries account-wide network and server failures against the same window', function () {
    Http::fake([
        'https://api.quickpay.net/payments?*' => Http::sequence()
            ->pushFailedConnection()
            ->push(['message' => 'temporarily unavailable'], 503)
            ->push([]),
    ]);
    $sleeps = [];
    $clockValues = [
        new DateTimeImmutable('2026-08-07T10:00:00.500000Z'),
        new DateTimeImmutable('2026-08-07T10:00:03.500000Z'),
    ];
    $events = [];
    $runner = callbackWatchRunner(
        sleeps: $sleeps,
        polls: 1,
        clock: function () use (&$clockValues): DateTimeImmutable {
            return array_shift($clockValues);
        },
        waitUntil: fn (DateTimeImmutable $time): null => null,
    );

    $runner->run(
        paymentId: null,
        orderId: null,
        target: CallbackTarget::fromString('http://localhost/callback'),
        apiKey: 'api-key',
        privateKey: 'private-key',
        interval: 2,
        observer: function (string $event, array $context) use (&$events): void {
            $events[] = [$event, $context];
        },
    );

    $scanUrls = collect(Http::recorded())
        ->map(fn (array $record): Request => $record[0])
        ->filter(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/payments')
        ->pluck('url')
        ->values()
        ->all();

    expect($scanUrls)->toHaveCount(3)
        ->and(array_unique($scanUrls))->toHaveCount(1)
        ->and($sleeps)->toBe([2, 2, 2])
        ->and($events)->toContain(
            ['polling-retry', ['status' => null, 'delay' => 2]],
            ['polling-retry', ['status' => 503, 'delay' => 2]],
        );
});

it('treats invalid operation timestamps as fatal during account-wide watching', function (mixed $timestamp) {
    Http::fake([
        'https://api.quickpay.net/payments?*' => Http::response([['id' => 42]]),
        'https://api.quickpay.net/payments/42' => Http::response(watchPayment([
            ['id' => 1, 'type' => 'authorize', 'created_at' => $timestamp],
        ])),
    ]);
    $sleeps = [];
    $clockValues = [
        new DateTimeImmutable('2026-08-07T10:00:00.500000Z'),
        new DateTimeImmutable('2026-08-07T10:00:03.500000Z'),
    ];
    $runner = callbackWatchRunner(
        sleeps: $sleeps,
        polls: 1,
        clock: function () use (&$clockValues): DateTimeImmutable {
            return array_shift($clockValues);
        },
        waitUntil: fn (DateTimeImmutable $time): null => null,
    );

    expect(fn () => $runner->run(
        paymentId: null,
        orderId: null,
        target: CallbackTarget::fromString('http://localhost/callback'),
        apiKey: 'api-key',
        privateKey: 'private-key',
        interval: 2,
        observer: fn (string $event, array $context): null => null,
    ))->toThrow(UnexpectedValueException::class, 'valid timestamp');

    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'http://localhost/callback');
})->with([
    'missing' => [null],
    'not ISO-8601' => ['2026-08-07 10:00:02'],
    'impossible date' => ['2026-02-30T10:00:02Z'],
    'non-string' => [42],
]);

it('treats a changed payment without an operations list as fatal', function () {
    Http::fake([
        'https://api.quickpay.net/payments?*' => Http::response([['id' => 42]]),
        'https://api.quickpay.net/payments/42' => Http::response([
            'id' => 42,
            'order_id' => 'order-42',
            'merchant_id' => 123,
        ]),
    ]);
    $sleeps = [];
    $clockValues = [
        new DateTimeImmutable('2026-08-07T10:00:00.500000Z'),
        new DateTimeImmutable('2026-08-07T10:00:03.500000Z'),
    ];
    $runner = callbackWatchRunner(
        sleeps: $sleeps,
        polls: 1,
        clock: function () use (&$clockValues): DateTimeImmutable {
            return array_shift($clockValues);
        },
        waitUntil: fn (DateTimeImmutable $time): null => null,
    );

    expect(fn () => $runner->run(
        paymentId: null,
        orderId: null,
        target: CallbackTarget::fromString('http://localhost/callback'),
        apiKey: 'api-key',
        privateKey: 'private-key',
        interval: 2,
        observer: fn (string $event, array $context): null => null,
    ))->toThrow(UnexpectedValueException::class, 'malformed operations');
});

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
function callbackWatchRunner(
    array &$sleeps,
    int $polls,
    ?callable $clock = null,
    ?callable $waitUntil = null,
): CallbackWatchRunner {
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
        clock: $clock,
        waitUntil: $waitUntil,
    );
}

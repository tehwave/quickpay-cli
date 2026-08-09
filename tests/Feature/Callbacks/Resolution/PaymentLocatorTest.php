<?php

use App\Callbacks\Resolution\PaymentLocator;
use App\Callbacks\Watching\CallbackPollingException;
use App\Quickpay\QuickpayClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('scans changed payments with the exact updated-at window and fetches each full resource once', function () {
    Http::fake([
        'https://api.quickpay.net/payments?*' => Http::response([
            ['id' => 42],
            ['id' => 42],
            ['id' => 43],
        ]),
        'https://api.quickpay.net/payments/42' => Http::response(['id' => 42, 'operations' => []]),
        'https://api.quickpay.net/payments/43' => Http::response(['id' => 43, 'operations' => []]),
    ]);

    $locator = new PaymentLocator(new QuickpayClient(app(Factory::class), 'api-key'));
    $payments = $locator->changedBetween(
        new DateTimeImmutable('2026-08-07T10:00:00+02:00'),
        new DateTimeImmutable('2026-08-07T10:00:05+02:00'),
    );

    expect(array_map(fn ($payment): mixed => $payment->json['id'], $payments))->toBe([42, 43]);

    Http::assertSent(function (Request $request): bool {
        if (parse_url($request->url(), PHP_URL_PATH) !== '/payments') {
            return false;
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query === [
            'timestamp' => 'updated_at',
            'min_time' => '2026-08-07 08:00:00 +0000',
            'max_time' => '2026-08-07 08:00:05 +0000',
            'operations_size' => '0',
            'page_size' => '100',
        ];
    });

    foreach (['42', '43'] as $paymentId) {
        expect(Http::recorded(fn (Request $request): bool => $request->url() === "https://api.quickpay.net/payments/{$paymentId}"))
            ->toHaveCount(1);
    }
});

it('scans every changed-payment page and deduplicates payment ids before full fetches', function () {
    Http::fake([
        'https://api.quickpay.net/payments?timestamp=updated_at*' => Http::response(
            [['id' => 42], ['id' => 42]],
            200,
            ['Link' => '<https://api.quickpay.net/payments?page=2&page_size=100>; rel="next"'],
        ),
        'https://api.quickpay.net/payments?page=2&page_size=100' => Http::response([
            ['id' => 42],
            ['id' => 43],
        ]),
        'https://api.quickpay.net/payments/42' => Http::response(['id' => 42, 'operations' => []]),
        'https://api.quickpay.net/payments/43' => Http::response(['id' => 43, 'operations' => []]),
    ]);

    $payments = paymentLocator()->changedBetween(
        new DateTimeImmutable('2026-08-07T08:00:00Z'),
        new DateTimeImmutable('2026-08-07T08:00:05Z'),
    );

    expect(array_map(fn ($payment): mixed => $payment->json['id'], $payments))->toBe([42, 43]);
    Http::assertSentCount(4);
});

it('rejects malformed changed-payment rows', function (mixed $row) {
    Http::fake(['https://api.quickpay.net/payments?*' => Http::response([$row])]);

    expect(fn () => paymentLocator()->changedBetween(
        new DateTimeImmutable('2026-08-07T08:00:00Z'),
        new DateTimeImmutable('2026-08-07T08:00:05Z'),
    ))->toThrow(UnexpectedValueException::class, 'valid payment ID');

    Http::assertSentCount(1);
})->with([
    'scalar row' => ['42'],
    'list row' => [[42]],
    'missing id' => [['order_id' => 'order-42']],
    'empty id' => [['id' => '']],
]);

it('rejects malformed changed-payment pages including later pages', function (mixed $page, bool $later) {
    Http::fake($later ? [
        'https://api.quickpay.net/payments?timestamp=updated_at*' => Http::response(
            [],
            200,
            ['Link' => '<https://api.quickpay.net/payments?page=2>; rel="next"'],
        ),
        'https://api.quickpay.net/payments?page=2' => Http::response($page),
    ] : [
        'https://api.quickpay.net/payments?*' => Http::response($page),
    ]);

    expect(fn () => paymentLocator()->changedBetween(
        new DateTimeImmutable('2026-08-07T08:00:00Z'),
        new DateTimeImmutable('2026-08-07T08:00:05Z'),
    ))->toThrow(UnexpectedValueException::class, 'malformed changed-payment response');
})->with([
    'object first page' => [['id' => 42], false],
    'scalar first page' => [42, false],
    'object second page' => [['id' => 42], true],
]);

it('detects changed-payment pagination cycles before repeating a request', function () {
    Http::fake([
        'https://api.quickpay.net/payments?timestamp=updated_at*' => Http::response(
            [],
            200,
            ['Link' => '<https://api.quickpay.net/payments?page=2>; rel="next"'],
        ),
        'https://api.quickpay.net/payments?page=2' => Http::response(
            [],
            200,
            ['Link' => '<https://api.quickpay.net/payments?page=2>; rel="next"'],
        ),
    ]);

    expect(fn () => paymentLocator()->changedBetween(
        new DateTimeImmutable('2026-08-07T08:00:00Z'),
        new DateTimeImmutable('2026-08-07T08:00:05Z'),
    ))->toThrow(InvalidArgumentException::class, 'pagination cycle');

    Http::assertSentCount(2);
});

it('rejects unsafe changed-payment pagination links before contacting their host', function () {
    Http::fake(['*' => Http::response(
        [],
        200,
        ['Link' => '<https://api.quickpay.net.evil.test/payments?page=2>; rel="next"'],
    )]);

    expect(fn () => paymentLocator()->changedBetween(
        new DateTimeImmutable('2026-08-07T08:00:00Z'),
        new DateTimeImmutable('2026-08-07T08:00:05Z'),
    ))->toThrow(InvalidArgumentException::class, 'Quickpay API origin');

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'evil.test'));
});

it('stops changed-payment pagination before requesting page 101', function () {
    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $page = isset($query['page']) ? (int) $query['page'] : 1;

        return Http::response(
            [],
            200,
            ['Link' => '<https://api.quickpay.net/payments?page='.($page + 1).'>; rel="next"'],
        );
    });

    expect(fn () => paymentLocator()->changedBetween(
        new DateTimeImmutable('2026-08-07T08:00:00Z'),
        new DateTimeImmutable('2026-08-07T08:00:05Z'),
    ))->toThrow(InvalidArgumentException::class, 'maximum of 100 pages');

    Http::assertSentCount(100);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'page=101'));
});

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

function paymentLocator(): PaymentLocator
{
    return new PaymentLocator(new QuickpayClient(app(Factory::class), 'api-key'));
}

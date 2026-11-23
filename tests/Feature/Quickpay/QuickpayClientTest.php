<?php

use App\Credentials\Exceptions\MerchantAccessException;
use App\Credentials\MerchantAccess;
use App\Quickpay\Exceptions\QuickpayRequestException;
use App\Quickpay\QuickpayClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('sends the required authentication version and json headers', function () {
    Http::fake(['https://api.quickpay.net/ping*' => Http::response(['scope' => 'merchant'])]);

    $client = new QuickpayClient(app(Factory::class), 'test-secret', MerchantAccess::full());
    $response = $client->get('/ping', ['probe' => 'yes'], [], ['X-Test' => 'allowed']);

    expect($response->successful())->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://api.quickpay.net/ping?probe=yes'
            && $request->header('Authorization') === ['Basic '.base64_encode(':test-secret')]
            && $request->header('Accept') === ['application/json']
            && $request->header('Accept-Version') === ['v10']
            && $request->header('Content-Type') === ['application/json']
            && $request->header('X-Test') === ['allowed'];
    });
});

it('supports each raw http method without retrying mutations', function (string $method) {
    Http::fake(['https://api.quickpay.net/resources*' => Http::response(['ok' => true])]);

    $client = new QuickpayClient(app(Factory::class), 'secret', MerchantAccess::full());
    $response = $client->raw($method, '/resources', ['page' => 2], ['name' => 'Example']);

    expect($response->successful())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === $method);
    Http::assertSentCount(1);
})->with(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']);

it('uses a thirty second request timeout', function () {
    $requestOptions = null;
    Http::fake(function (Request $request, array $options) use (&$requestOptions) {
        $requestOptions = $options;

        return Http::response(['ok' => true]);
    });

    $client = new QuickpayClient(app(Factory::class), 'secret', MerchantAccess::full());
    $client->get('/ping');

    expect($requestOptions)->toBeArray()
        ->and($requestOptions['timeout'] ?? null)->toBe(30);
});

it('does not retry a failed raw mutation', function (string $method) {
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('Mutation failed');
    });

    $client = new QuickpayClient(app(Factory::class), 'secret', MerchantAccess::full());

    expect(fn () => $client->raw($method, '/resources', data: ['name' => 'Example']))
        ->toThrow(QuickpayRequestException::class);
    expect($attempts)->toBe(1);
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

it('rejects unvalidated absolute urls', function () {
    Http::fake();

    $client = new QuickpayClient(app(Factory::class), 'secret', MerchantAccess::full());

    expect(fn () => $client->get('https://example.com/payments'))
        ->toThrow(InvalidArgumentException::class, 'relative');

    Http::assertNothingSent();
});

it('prevents mixed-case extra headers from overriding request security', function (string $header) {
    Http::fake();

    $client = new QuickpayClient(app(Factory::class), 'secret', MerchantAccess::full());

    expect(fn () => $client->get('/ping', headers: [$header => 'override']))
        ->toThrow(InvalidArgumentException::class, 'cannot be overridden');

    Http::assertNothingSent();
})->with(['aUtHoRiZaTiOn', 'hOsT', 'AcCePt-VeRsIoN', 'aCcEpT', 'CoNtEnT-TyPe']);

it('turns connection failures into a safe exception', function () {
    Http::fake(fn () => throw new ConnectionException('Failed using test-secret'));

    $client = new QuickpayClient(app(Factory::class), 'test-secret', MerchantAccess::full());

    try {
        $client->get('/ping');
        $this->fail('Expected a Quickpay request exception.');
    } catch (QuickpayRequestException $exception) {
        expect($exception->getMessage())
            ->toContain('Unable to connect to Quickpay')
            ->not->toContain('test-secret')
            ->and($exception->getPrevious())->toBeNull();
    }
});

it('sends no write request from a read-only merchant', function (string $method): void {
    Http::fake();
    $client = new QuickpayClient(app(Factory::class), 'test-secret', new MerchantAccess(false));

    expect(fn () => $client->raw($method, '/payments/42', data: ['amount' => 1000]))
        ->toThrow(MerchantAccessException::class, 'is read-only, so the '.strtoupper($method).' request was not sent.');
    Http::assertNothingSent();
})->with(['POST', 'PUT', 'PATCH', 'delete']);

it('applies write protection to first-class requests', function (): void {
    Http::fake(['https://api.quickpay.net/payments*' => Http::response(['id' => 42])]);
    $readOnly = new QuickpayClient(app(Factory::class), 'test-secret', new MerchantAccess(false));

    expect($readOnly->get('/payments/42')->successful())->toBeTrue()
        ->and($readOnly->raw('GET', '/payments/42')->successful())->toBeTrue()
        ->and(fn () => $readOnly->post('/payments'))->toThrow(MerchantAccessException::class)
        ->and(fn () => $readOnly->put('/payments/42/link'))->toThrow(MerchantAccessException::class);
    expect(Http::recorded())->toHaveCount(2)
        ->and(Http::recorded()[0][0]->method())->toBe('GET')
        ->and(Http::recorded()[1][0]->method())->toBe('GET');
});

it('never treats blocked access as a retryable request failure', function (): void {
    expect(is_subclass_of(MerchantAccessException::class, QuickpayRequestException::class))->toBeFalse();
});

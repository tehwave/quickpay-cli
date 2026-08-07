<?php

use App\Quickpay\Exceptions\QuickpayRequestException;
use App\Quickpay\QuickpayClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('sends the required authentication version and json headers', function () {
    Http::fake(['https://api.quickpay.net/ping*' => Http::response(['scope' => 'merchant'])]);

    $client = new QuickpayClient(app(Factory::class), 'test-secret');
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

it('supports each required http method without retrying mutations', function (string $method) {
    Http::fake(['https://api.quickpay.net/resources*' => Http::response(['ok' => true])]);

    $client = new QuickpayClient(app(Factory::class), 'secret');
    $response = $client->{strtolower($method)}('/resources', ['page' => 2], ['name' => 'Example']);

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

    $client = new QuickpayClient(app(Factory::class), 'secret');
    $client->get('/ping');

    expect($requestOptions)->toBeArray()
        ->and($requestOptions['timeout'] ?? null)->toBe(30);
});

it('does not retry a failed mutation', function (string $method) {
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('Mutation failed');
    });

    $client = new QuickpayClient(app(Factory::class), 'secret');

    expect(fn () => $client->{strtolower($method)}('/resources', data: ['name' => 'Example']))
        ->toThrow(QuickpayRequestException::class);
    expect($attempts)->toBe(1);
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

it('rejects unvalidated absolute urls', function () {
    Http::fake();

    $client = new QuickpayClient(app(Factory::class), 'secret');

    expect(fn () => $client->get('https://example.com/payments'))
        ->toThrow(InvalidArgumentException::class, 'relative');

    Http::assertNothingSent();
});

it('prevents mixed-case extra headers from overriding request security', function (string $header) {
    Http::fake();

    $client = new QuickpayClient(app(Factory::class), 'secret');

    expect(fn () => $client->get('/ping', headers: [$header => 'override']))
        ->toThrow(InvalidArgumentException::class, 'cannot be overridden');

    Http::assertNothingSent();
})->with(['aUtHoRiZaTiOn', 'hOsT', 'AcCePt-VeRsIoN', 'aCcEpT', 'CoNtEnT-TyPe']);

it('turns connection failures into a safe exception', function () {
    Http::fake(fn () => throw new ConnectionException('Failed using test-secret'));

    $client = new QuickpayClient(app(Factory::class), 'test-secret');

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

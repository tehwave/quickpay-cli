<?php

use App\Callbacks\Delivery\CallbackDelivery;
use App\Callbacks\Delivery\CallbackDeliveryFailure;
use App\Callbacks\Delivery\CallbackForwarder;
use App\Callbacks\Delivery\CallbackTarget;
use App\Callbacks\Signing\CallbackEnvelope;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function callbackEnvelope(): CallbackEnvelope
{
    $body = '{"id":42,"merchant_id":123}';

    return new CallbackEnvelope('42', 'order-42', $body, [
        'Content-Type' => 'application/json',
        'QuickPay-Resource-Type' => 'Payment',
        'QuickPay-Account-ID' => '123',
        'QuickPay-API-Version' => 'v10',
        'QuickPay-Checksum-Sha256' => hash_hmac('sha256', $body, 'private-key'),
    ]);
}

it('rejects a successful delivery with a failure reason', function () {
    expect(fn () => new CallbackDelivery(
        url: 'https://merchant.example/callback',
        status: 204,
        successful: true,
        failure: CallbackDeliveryFailure::Network,
    ))->toThrow(InvalidArgumentException::class);
});

it('posts the exact signed envelope without Quickpay authentication headers', function () {
    Http::fake(['http://localhost:8000/hooks/quickpay' => Http::response('', 204)]);

    $delivery = (new CallbackForwarder(app(Factory::class)))
        ->deliver(CallbackTarget::fromString('http://localhost:8000/hooks/quickpay'), callbackEnvelope());

    expect($delivery->successful)->toBeTrue()
        ->and($delivery->status)->toBe(204)
        ->and($delivery->url)->toBe('http://localhost:8000/hooks/quickpay')
        ->and($delivery->failure)->toBeNull()
        ->and($delivery->isRetryable())->toBeFalse();

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->body() === callbackEnvelope()->body
            && $request->header('QuickPay-Checksum-Sha256') === [callbackEnvelope()->headers['QuickPay-Checksum-Sha256']]
            && $request->header('Authorization') === []
            && $request->header('Accept-Version') === [];
    });
});

it('classifies documented callback response statuses', function (int $status, bool $successful, ?string $failure, bool $retryable) {
    Http::fake(['https://merchant.example/callback' => Http::response('', $status)]);

    $delivery = (new CallbackForwarder(app(Factory::class)))
        ->deliver(CallbackTarget::fromString('https://merchant.example/callback'), callbackEnvelope());

    expect($delivery->successful)->toBe($successful)
        ->and($delivery->status)->toBe($status)
        ->and($delivery->failure?->name)->toBe($failure)
        ->and($delivery->isRetryable())->toBe($retryable);
})->with([
    '200' => [200, true, null, false],
    '299' => [299, true, null, false],
    '302' => [302, true, null, false],
    '303' => [303, true, null, false],
    '300' => [300, false, 'HttpResponse', false],
    '400' => [400, false, 'HttpResponse', false],
    '404' => [404, false, 'HttpResponse', false],
    '405' => [405, false, 'HttpResponse', false],
    '408' => [408, false, 'HttpResponse', true],
    '422' => [422, false, 'HttpResponse', false],
    '425' => [425, false, 'HttpResponse', true],
    '429' => [429, false, 'HttpResponse', true],
]);

it('classifies every server error response as retryable', function (int $status) {
    Http::fake(['https://merchant.example/callback' => Http::response('', $status)]);

    $delivery = (new CallbackForwarder(app(Factory::class)))
        ->deliver(CallbackTarget::fromString('https://merchant.example/callback'), callbackEnvelope());

    expect($delivery->successful)->toBeFalse()
        ->and($delivery->status)->toBe($status)
        ->and($delivery->failure?->name)->toBe('HttpResponse')
        ->and($delivery->isRetryable())->toBeTrue();
})->with(range(500, 599));

it('manually follows 301 and 307 while preserving the post body and callback headers', function () {
    Http::fake([
        'https://merchant.example/start' => Http::response('', 301, ['Location' => '/moved']),
        'https://merchant.example/moved' => Http::response('', 307, ['Location' => 'https://receiver.example/final']),
        'https://receiver.example/final' => Http::response('', 204),
    ]);

    $delivery = (new CallbackForwarder(app(Factory::class)))
        ->deliver(CallbackTarget::fromString('https://merchant.example/start'), callbackEnvelope());

    expect($delivery->successful)->toBeTrue()
        ->and($delivery->url)->toBe('https://receiver.example/final')
        ->and($delivery->redirects)->toBe(2)
        ->and($delivery->failure)->toBeNull()
        ->and($delivery->isRetryable())->toBeFalse();
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://receiver.example/final'
        && $request->method() === 'POST'
        && $request->body() === callbackEnvelope()->body);
});

it('classifies a network failure as retryable without exposing its error details', function () {
    Http::fake(fn () => throw new ConnectionException('secret local failure body'));

    $delivery = (new CallbackForwarder(app(Factory::class)))
        ->deliver(CallbackTarget::fromString('https://merchant.example/callback'), callbackEnvelope());

    expect($delivery->successful)->toBeFalse()
        ->and($delivery->status)->toBeNull()
        ->and($delivery->failure?->name)->toBe('Network')
        ->and($delivery->isRetryable())->toBeTrue();
});

it('classifies cyclic redirects as terminal redirect-policy failures', function () {
    Http::fake(['https://merchant.example/callback' => Http::response('', 307, [
        'Location' => 'https://merchant.example/callback',
    ])]);

    $delivery = (new CallbackForwarder(app(Factory::class)))
        ->deliver(CallbackTarget::fromString('https://merchant.example/callback'), callbackEnvelope());

    expect($delivery->successful)->toBeFalse()
        ->and($delivery->status)->toBeNull()
        ->and($delivery->failure?->name)->toBe('RedirectRejected')
        ->and($delivery->isRetryable())->toBeFalse();
});

it('classifies missing redirect locations as terminal redirect-policy failures', function () {
    Http::fake(['https://merchant.example/callback' => Http::response('', 301)]);

    $delivery = (new CallbackForwarder(app(Factory::class)))
        ->deliver(CallbackTarget::fromString('https://merchant.example/callback'), callbackEnvelope());

    expect($delivery->successful)->toBeFalse()
        ->and($delivery->status)->toBeNull()
        ->and($delivery->failure?->name)->toBe('RedirectRejected')
        ->and($delivery->isRetryable())->toBeFalse();
});

it('classifies malformed redirect locations as terminal redirect-policy failures', function () {
    Http::fake(['https://merchant.example/callback' => Http::response('', 301, [
        'Location' => 'https://',
    ])]);

    $delivery = (new CallbackForwarder(app(Factory::class)))
        ->deliver(CallbackTarget::fromString('https://merchant.example/callback'), callbackEnvelope());

    expect($delivery->successful)->toBeFalse()
        ->and($delivery->status)->toBeNull()
        ->and($delivery->failure?->name)->toBe('RedirectRejected')
        ->and($delivery->isRetryable())->toBeFalse();
});

it('refuses to downgrade an https callback redirect to plaintext http', function () {
    Http::fake([
        'https://merchant.example/callback' => Http::response('', 307, [
            'Location' => 'http://merchant.example/insecure',
        ]),
        'http://merchant.example/insecure' => Http::response('', 204),
    ]);

    $delivery = (new CallbackForwarder(app(Factory::class)))
        ->deliver(CallbackTarget::fromString('https://merchant.example/callback'), callbackEnvelope());

    expect($delivery->successful)->toBeFalse()
        ->and($delivery->status)->toBeNull()
        ->and($delivery->failure?->name)->toBe('RedirectRejected')
        ->and($delivery->isRetryable())->toBeFalse();
    Http::assertSentCount(1);
});

it('classifies excessive redirects as terminal redirect-policy failures', function () {
    Http::fake([
        'https://merchant.example/start' => Http::response('', 307, ['Location' => '/one']),
        'https://merchant.example/one' => Http::response('', 307, ['Location' => '/two']),
        'https://merchant.example/two' => Http::response('', 307, ['Location' => '/three']),
        'https://merchant.example/three' => Http::response('', 307, ['Location' => '/four']),
        'https://merchant.example/four' => Http::response('', 307, ['Location' => '/five']),
        'https://merchant.example/five' => Http::response('', 307, ['Location' => '/six']),
        'https://merchant.example/six' => Http::response('', 204),
    ]);

    $delivery = (new CallbackForwarder(app(Factory::class)))
        ->deliver(CallbackTarget::fromString('https://merchant.example/start'), callbackEnvelope());

    expect($delivery->successful)->toBeFalse()
        ->and($delivery->status)->toBeNull()
        ->and($delivery->redirects)->toBe(5)
        ->and($delivery->failure?->name)->toBe('RedirectRejected')
        ->and($delivery->isRetryable())->toBeFalse();
    Http::assertSentCount(6);
});

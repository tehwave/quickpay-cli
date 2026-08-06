<?php

use App\Callbacks\CallbackEnvelope;
use App\Callbacks\CallbackForwarder;
use App\Callbacks\CallbackTarget;
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

it('posts the exact signed envelope without Quickpay authentication headers', function () {
    Http::fake(['http://localhost:8000/hooks/quickpay' => Http::response('', 204)]);

    $delivery = (new CallbackForwarder(app(Factory::class)))
        ->deliver(CallbackTarget::fromString('http://localhost:8000/hooks/quickpay'), callbackEnvelope());

    expect($delivery->successful)->toBeTrue()
        ->and($delivery->status)->toBe(204)
        ->and($delivery->url)->toBe('http://localhost:8000/hooks/quickpay');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->body() === callbackEnvelope()->body
            && $request->header('QuickPay-Checksum-Sha256') === [callbackEnvelope()->headers['QuickPay-Checksum-Sha256']]
            && $request->header('Authorization') === []
            && $request->header('Accept-Version') === [];
    });
});

it('treats only documented terminal callback statuses as success', function (int $status, bool $successful) {
    Http::fake(['https://merchant.example/callback' => Http::response('', $status)]);

    $delivery = (new CallbackForwarder(app(Factory::class)))
        ->deliver(CallbackTarget::fromString('https://merchant.example/callback'), callbackEnvelope());

    expect($delivery->successful)->toBe($successful)
        ->and($delivery->status)->toBe($status);
})->with([
    '200' => [200, true],
    '299' => [299, true],
    '302' => [302, true],
    '303' => [303, true],
    '300' => [300, false],
    '400' => [400, false],
    '500' => [500, false],
]);

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
        ->and($delivery->redirects)->toBe(2);
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://receiver.example/final'
        && $request->method() === 'POST'
        && $request->body() === callbackEnvelope()->body);
});

it('fails safely on a redirect loop or connection error', function (string $mode) {
    if ($mode === 'loop') {
        Http::fake(['https://merchant.example/callback' => Http::response('', 307, [
            'Location' => 'https://merchant.example/callback',
        ])]);
    } elseif ($mode === 'missing location') {
        Http::fake(['https://merchant.example/callback' => Http::response('', 301)]);
    } else {
        Http::fake(fn () => throw new ConnectionException('secret local failure body'));
    }

    $delivery = (new CallbackForwarder(app(Factory::class)))
        ->deliver(CallbackTarget::fromString('https://merchant.example/callback'), callbackEnvelope());

    expect($delivery->successful)->toBeFalse()
        ->and($delivery->status)->toBeNull();
})->with(['loop', 'missing location', 'connection']);

it('refuses to downgrade an https callback redirect to plaintext http', function () {
    Http::fake([
        'https://merchant.example/callback' => Http::response('', 307, [
            'Location' => 'http://merchant.example/insecure',
        ]),
        'http://merchant.example/insecure' => Http::response('', 204),
    ]);

    $delivery = (new CallbackForwarder(app(Factory::class)))
        ->deliver(CallbackTarget::fromString('https://merchant.example/callback'), callbackEnvelope());

    expect($delivery->successful)->toBeFalse();
    Http::assertSentCount(1);
});

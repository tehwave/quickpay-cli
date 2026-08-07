<?php

use App\Callbacks\Delivery\CallbackTarget;

it('accepts explicit http and https callback destinations', function (string $url) {
    expect(CallbackTarget::fromString($url)->url)->toBe($url);
})->with([
    'localhost' => 'http://localhost:8000/quickpay/callback?source=cli',
    'ipv4 loopback' => 'http://127.0.0.1:3000/hooks/quickpay',
    'public https' => 'https://merchant.example/hooks/quickpay',
]);

it('rejects callback destinations that are ambiguous or unsafe to parse', function (string $url) {
    expect(fn () => CallbackTarget::fromString($url))
        ->toThrow(InvalidArgumentException::class, 'valid HTTP or HTTPS URL');
})->with([
    'relative URL' => '/hooks/quickpay',
    'unsupported scheme' => 'file:///tmp/callback',
    'missing host' => 'http:///callback',
    'userinfo' => 'https://user:password@merchant.example/callback',
    'fragment' => 'https://merchant.example/callback#secret',
    'invalid port' => 'https://merchant.example:99999/callback',
    'control character' => "https://merchant.example/callback\nX-Injected: yes",
]);

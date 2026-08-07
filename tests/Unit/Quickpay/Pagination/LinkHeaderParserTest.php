<?php

use App\Quickpay\Pagination\LinkHeaderParser;

it('finds the link whose relation tokens include exactly next', function () {
    $header = '<https://api.quickpay.net/payments?page_key=previous>; rel="prev", '
        .'<https://api.quickpay.net/payments?page_key=next>; title="comma, safe"; rel="alternate next"';

    expect(LinkHeaderParser::next($header))
        ->toBe('/payments?page_key=next');
});

it('supports an unquoted next relation', function () {
    expect(LinkHeaderParser::next('<https://api.quickpay.net/payments?page_key=abc>; rel=next'))
        ->toBe('/payments?page_key=abc');
});

it('does not match relation names that merely contain next', function () {
    expect(LinkHeaderParser::next('<https://api.quickpay.net/payments?page=2>; rel="next-page"'))
        ->toBeNull();
});

it('accepts the normalized quickpay https origin and default port', function (string $url) {
    expect(LinkHeaderParser::next("<{$url}>; rel=next"))
        ->toBe('/payments?page=2');
})->with([
    'https://API.QUICKPAY.NET/payments?page=2',
    'https://api.quickpay.net:443/payments?page=2',
]);

it('rejects hostile or malformed next link urls', function (string $url) {
    expect(fn () => LinkHeaderParser::next("<{$url}>; rel=next"))
        ->toThrow(InvalidArgumentException::class, 'Quickpay API origin');
})->with([
    'http://api.quickpay.net/payments?page=2',
    'https://api.quickpay.net.evil.test/payments?page=2',
    'https://evil-api.quickpay.net/payments?page=2',
    'https://attacker@api.quickpay.net/payments?page=2',
    'https://api.quickpay.net:444/payments?page=2',
    'https://api.quickpay.net/payments?page=2#fragment',
    '//api.quickpay.net/payments?page=2',
    'not-a-url',
]);

it('rejects a malformed link header', function () {
    expect(fn () => LinkHeaderParser::next('<https://api.quickpay.net/payments?page=2; rel=next'))
        ->toThrow(InvalidArgumentException::class, 'Malformed Link header');
});

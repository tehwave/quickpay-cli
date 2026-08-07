<?php

use App\Quickpay\Raw\RawApiRequest;

it('normalizes methods and merges parsed query inputs', function (): void {
    $request = RawApiRequest::from(
        'get',
        '/payments?state=pending',
        ['state=processed', 'page=2'],
        [],
        null,
        ['X-Test:value'],
    );

    expect($request->method)->toBe('GET')
        ->and($request->path)->toBe('/payments')
        ->and($request->query)->toBe(['state' => 'processed', 'page' => '2'])
        ->and($request->headers)->toBe(['X-Test' => 'value'])
        ->and($request->mutation)->toBeFalse();
});

it('parses explicit json bodies without conflating an empty object with no body', function (): void {
    $request = RawApiRequest::from('POST', '/payments', [], [], '{}', []);

    expect($request->hasData)->toBeTrue()
        ->and($request->data)->toEqual((object) []);
});

it('rejects conflicting body inputs', function (): void {
    expect(fn (): RawApiRequest => RawApiRequest::from('POST', '/payments', [], ['id=1'], '{}', []))
        ->toThrow(InvalidArgumentException::class, 'mutually exclusive');
});

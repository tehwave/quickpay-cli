<?php

use App\Quickpay\QuickpayResponse;

it('keeps status headers raw body and decoded json', function () {
    $raw = '{"scope":"merchant","version":"v10"}';
    $response = new QuickpayResponse(200, ['Content-Type' => ['application/json']], $raw, json_decode($raw, true));

    expect($response->status)->toBe(200)
        ->and($response->rawBody)->toBe($raw)
        ->and($response->json)->toBe(['scope' => 'merchant', 'version' => 'v10'])
        ->and($response->successful())->toBeTrue();
});

it('accesses response headers without case sensitivity', function () {
    $response = new QuickpayResponse(200, ['Link' => ['<https://api.quickpay.net/payments?page=2>; rel="next"']], '', null);

    expect($response->header('link'))->toBe('<https://api.quickpay.net/payments?page=2>; rel="next"')
        ->and($response->header('missing'))->toBeNull();
});

it('builds a readable summary from quickpay error fields', function () {
    $response = new QuickpayResponse(422, [], '{}', [
        'message' => 'Payment was rejected',
        'errors' => ['amount' => ['Must be positive'], 'currency' => 'Unsupported'],
        'error_code' => 'validation_error',
        'qp_status_code' => '40000',
        'qp_status_msg' => 'Rejected by Quickpay',
        'aq_status_code' => '05',
        'aq_status_msg' => 'Do not honor',
    ]);

    expect($response->errorSummary())
        ->toStartWith('HTTP 422: Payment was rejected')
        ->toContain('amount: Must be positive')
        ->toContain('currency: Unsupported')
        ->toContain('error_code: validation_error')
        ->toContain('qp_status_code: 40000')
        ->toContain('qp_status_msg: Rejected by Quickpay')
        ->toContain('aq_status_code: 05')
        ->toContain('aq_status_msg: Do not honor');
});

it('falls back to the http status when an error has no structured fields', function () {
    $response = new QuickpayResponse(503, [], '<html>Unavailable</html>', null);

    expect($response->errorSummary())->toBe('Quickpay request failed with HTTP 503.');
});

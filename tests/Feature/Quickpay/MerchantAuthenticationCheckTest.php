<?php

use App\Credentials\ApiKey;
use App\Quickpay\AuthenticatedQuickpayFactory;
use App\Quickpay\MerchantAuthenticationCheck;
use Illuminate\Support\Facades\Http;

it('requires a literal merchant scope from a successful check', function (): void {
    Http::fake(['*' => Http::response(['scope' => 'user'])]);
    expect(fn () => (new MerchantAuthenticationCheck(app(AuthenticatedQuickpayFactory::class)))->check(new ApiKey('fake-key', 'provided')))
        ->toThrow(UnexpectedValueException::class, 'merchant');
    Http::assertSentCount(1);
});

<?php

use Illuminate\Support\Facades\Http;

it('removes only the named credential without choosing another default', function (): void {
    $this->store->add('My Other Website', 'fake-secret-a');
    $this->store->add('My Website', 'fake-secret-b');
    $this->artisan('merchants:remove', ['name' => 'My Other Website'])->assertExitCode(0);
    expect($this->store->read())->toBe(['default_merchant' => null, 'merchants' => ['My Website' => ['api_key' => 'fake-secret-b']]]);
    $this->artisan('merchants:remove', ['name' => 'shop-missing'])->assertExitCode(1);
    $this->artisan('merchants:remove', ['name' => 'My Website'])->assertExitCode(0);
    expect($this->store->read()['merchants'])->toBe([]);
    Http::assertNothingSent();
});

it('explains that environment credentials remain after local removal', function (): void {
    $this->store->add('shop-a', 'fake-secret-a');
    putenv('QUICKPAY_API_KEY=fake-environment');
    $this->artisan('merchants:remove', ['name' => 'shop-a'])->expectsOutputToContain('QUICKPAY_API_KEY remains configured')->assertExitCode(0);
    expect(getenv('QUICKPAY_API_KEY'))->toBe('fake-environment');
    Http::assertNothingSent();
});

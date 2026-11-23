<?php

use App\Commands\Merchants\ListMerchantsCommand;
use Illuminate\Support\Facades\Http;

it('lists only sorted names and the global default', function (): void {
    $this->store->add('My Website', 'fake-secret-b');
    $this->store->add('My Other Website', 'fake-secret-a');
    [$exit, $output, $errors] = merchantCommandResult(ListMerchantsCommand::class, ['--json' => true]);
    expect($exit)->toBe(0)->and($errors)->toBe('')->and(json_decode($output, true, flags: JSON_THROW_ON_ERROR))
        ->toBe([['name' => 'My Other Website', 'default' => false, 'write' => true], ['name' => 'My Website', 'default' => true, 'write' => true]]);
    Http::assertNothingSent();
});

it('lists an empty store without contacting Quickpay', function (): void {
    [$exit, $output] = merchantCommandResult(ListMerchantsCommand::class, ['--json' => true]);
    expect($exit)->toBe(0)->and($output)->toBe('[]');
    Http::assertNothingSent();
});

it('never lists labels that expose another saved API key', function (): void {
    file_put_contents($this->store->path(), '{"default_merchant":"shop-a","merchants":{"shop-a":{"api_key":"fake-secret-a","write":true},"fake-secret-a":{"api_key":"fake-secret-b","write":true}}}');
    [$exit, $output, $errors] = merchantCommandResult(ListMerchantsCommand::class, ['--json' => true]);
    expect($exit)->toBe(1)->and($output)->toBe('')
        ->and($errors)->not->toContain('fake-secret-a', 'fake-secret-b');
    Http::assertNothingSent();
});

it('shows access only when a merchant is read-only', function (): void {
    $this->store->add('shop-a', 'fake-secret-a');
    $this->store->add('shop-b', 'fake-secret-b', write: false);
    [$exit, $output] = merchantCommandResult(ListMerchantsCommand::class, ['--json' => true]);
    expect($exit)->toBe(0)->and(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toBe([
        ['name' => 'shop-a', 'default' => true, 'write' => true],
        ['name' => 'shop-b', 'default' => false, 'write' => false],
    ]);
    [$exit, $output] = merchantCommandResult(ListMerchantsCommand::class);
    expect($exit)->toBe(0)->and($output)->toContain('Access')->toContain('read-only')
        ->not->toContain('read and write');
    Http::assertNothingSent();
});

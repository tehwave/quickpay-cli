<?php

use App\Commands\Merchants\ActiveMerchantCommand;
use Illuminate\Support\Facades\Http;

it('selects saved merchants globally without re-entering credentials', function (): void {
    $this->store->add('My Other Website', 'fake-secret-a');
    $this->store->add('My Website', 'fake-secret-b');
    $this->artisan('merchants:select', ['name' => 'My Website'])->assertExitCode(0);
    expect($this->store->read()['default_merchant'])->toBe('My Website');
    Http::assertNothingSent();
});

it('rejects multiple merchant names without changing selection', function (): void {
    $this->store->add('shop-a', 'fake-secret-a');
    $before = file_get_contents($this->store->path());
    $this->artisan('merchants:select', ['name' => ['shop-a', 'shop-b']])->assertExitCode(1);
    expect(file_get_contents($this->store->path()))->toBe($before);
    Http::assertNothingSent();
});

it('offers a sorted merchant picker with cancellation', function (string $selection): void {
    $this->store->add('shop-a', 'fake-secret-a');
    $this->store->add('shop-b', 'fake-secret-b');
    $this->store->add('Cancel', 'fake-cancel-key');
    $this->artisan('merchants:select')->expectsChoice('Select merchant', $selection, ['Cancel', 'shop-a', 'shop-b', '[Cancel]'])->assertExitCode(0);
    expect($this->store->read()['default_merchant'])->toBe($selection === '[Cancel]' ? 'shop-a' : $selection);
    Http::assertNothingSent();
})->with(['selection' => 'shop-b', 'merchant named Cancel' => 'Cancel', 'cancel' => '[Cancel]']);

it('selects a project merchant without changing the global default', function (): void {
    $this->store->add('My Other Website', 'fake-secret-a');
    $this->store->add('My Website', 'fake-secret-b');
    $this->artisan('merchants:select', ['name' => 'My Website', '--local' => true])->assertExitCode(0);
    chdir($this->tempDirectory.'/project/nested');
    [$exit, $output] = merchantCommandResult(ActiveMerchantCommand::class, ['--json' => true]);
    expect($exit)->toBe(0)->and(json_decode($output, true)['merchant'])->toBe('My Website')
        ->and($this->store->read()['default_merchant'])->toBe('My Other Website');
    mkdir($this->tempDirectory.'/other');
    chdir($this->tempDirectory.'/other');
    [$exit, $output] = merchantCommandResult(ActiveMerchantCommand::class, ['--json' => true]);
    expect(json_decode($output, true)['merchant'])->toBe('My Other Website');
    Http::assertNothingSent();
});

it('rejects environment overrides before changing selection', function (bool $named): void {
    $this->store->add('shop-a', 'fake-secret-a');
    putenv('QUICKPAY_API_KEY=fake-environment');
    $before = file_get_contents($this->store->path());
    $this->artisan('merchants:select', $named ? ['name' => 'shop-a'] : [])
        ->expectsOutputToContain('QUICKPAY_API_KEY')->assertExitCode(1);
    expect(file_get_contents($this->store->path()))->toBe($before);
    Http::assertNothingSent();
})->with(['named' => true, 'picker' => false]);

it('rejects empty unknown and unattended picker selections', function (array $arguments): void {
    $this->artisan('merchants:select', $arguments)->assertExitCode(1);
    expect(file_exists($this->store->path()))->toBeFalse();
    Http::assertNothingSent();
})->with(['empty' => [[]], 'unknown' => [['name' => 'shop-missing']], 'unattended' => [['--no-interaction' => true]]]);

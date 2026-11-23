<?php

use App\Console\Terminal\StdinTerminalDetector;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('makes a merchant read-only without an interactive terminal', function (): void {
    $this->store->add('shop-a', 'fake-secret-a');
    $this->artisan('merchants:access', ['name' => 'shop-a', '--no-write' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Access for shop-a: read-only')->assertExitCode(0);
    expect($this->store->read()['merchants']['shop-a'])->toBe(['api_key' => 'fake-secret-a', 'write' => false]);
    Http::assertNothingSent();
});

it('allows what is already allowed without asking or writing', function (): void {
    $this->store->add('shop-a', 'fake-secret-a');
    $before = file_get_contents($this->store->path());
    $this->artisan('merchants:access', ['name' => 'shop-a', '--write' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Access for shop-a: read and write')->assertExitCode(0);
    expect(file_get_contents($this->store->path()))->toBe($before);
});

it('allows writes again only after an interactive confirmation', function (): void {
    $this->store->add('shop-a', 'fake-secret-a', write: false);
    $this->artisan('merchants:access', ['name' => 'shop-a', '--write' => true])
        ->expectsConfirmation('Allow write requests for shop-a?', 'yes')
        ->expectsOutputToContain('Access for shop-a: read and write')->assertExitCode(0);
    expect($this->store->read()['merchants']['shop-a'])->toBe(['api_key' => 'fake-secret-a', 'write' => true]);
});

it('has no option to block reads', function (string $command): void {
    expect(Artisan::all()[$command]->getDefinition()->hasOption('read'))->toBeFalse()
        ->and(Artisan::all()[$command]->getDefinition()->hasNegation('no-read'))->toBeFalse();
})->with(['merchants:access', 'merchants:add']);

it('leaves access unchanged when the confirmation is declined', function (): void {
    $this->store->add('shop-a', 'fake-secret-a', write: false);
    $before = file_get_contents($this->store->path());
    $this->artisan('merchants:access', ['name' => 'shop-a', '--write' => true])
        ->expectsConfirmation('Allow write requests for shop-a?', 'no')
        ->expectsOutputToContain('Cancelled')->assertExitCode(0);
    expect(file_get_contents($this->store->path()))->toBe($before);
});

it('refuses to allow requests without an interactive terminal', function (bool $tty): void {
    if ($tty) {
        app()->instance(StdinTerminalDetector::class, new class implements StdinTerminalDetector
        {
            public function isTty(): bool
            {
                return false;
            }
        });
    }
    $this->store->add('shop-a', 'fake-secret-a', write: false);
    $before = file_get_contents($this->store->path());
    $this->artisan('merchants:access', ['name' => 'shop-a', '--write' => true, '--no-interaction' => ! $tty])
        ->expectsOutputToContain('interactive terminal')->assertExitCode(1);
    expect(file_get_contents($this->store->path()))->toBe($before);
})->with(['not interactive' => false, 'not a tty' => true]);

it('shows the current access when no flag is given', function (): void {
    $this->store->add('shop-a', 'fake-secret-a', write: false);
    $before = file_get_contents($this->store->path());
    $this->artisan('merchants:access', ['name' => 'shop-a', '--no-interaction' => true])
        ->expectsOutputToContain('Access for shop-a: read-only')->assertExitCode(0);
    expect(file_get_contents($this->store->path()))->toBe($before);
});

it('rejects unsaved and invalid names without writing', function (string $name): void {
    $this->store->add('shop-a', 'fake-secret-a');
    $before = file_get_contents($this->store->path());
    $this->artisan('merchants:access', ['name' => $name, '--no-write' => true])->assertExitCode(1);
    expect(file_get_contents($this->store->path()))->toBe($before);
    Http::assertNothingSent();
})->with(['unsaved' => 'shop-b', 'invalid' => '../shop-a']);

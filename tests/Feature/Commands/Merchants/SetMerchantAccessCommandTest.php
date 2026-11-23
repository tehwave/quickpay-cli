<?php

use App\Console\Terminal\StdinTerminalDetector;
use Illuminate\Support\Facades\Http;

it('restricts access without an interactive terminal', function (array $flags, array $saved, string $label): void {
    $this->store->add('shop-a', 'fake-secret-a');
    $this->artisan('merchants:access', ['name' => 'shop-a', ...$flags, '--no-interaction' => true])
        ->expectsOutputToContain('Access for shop-a: '.$label)->assertExitCode(0);
    expect($this->store->read()['merchants']['shop-a'])->toBe(['api_key' => 'fake-secret-a', ...$saved]);
    Http::assertNothingSent();
})->with([
    'block writes' => [['--no-write' => true], ['read' => true, 'write' => false], 'read-only'],
    'block reads' => [['--no-read' => true], ['read' => false, 'write' => true], 'write-only'],
    'block both' => [['--no-read' => true, '--no-write' => true], ['read' => false, 'write' => false], 'no API access'],
]);

it('allows blocked requests again only after an interactive confirmation', function (array $flags, string $question, array $saved): void {
    $this->store->add('shop-a', 'fake-secret-a', read: false, write: false);
    $this->artisan('merchants:access', ['name' => 'shop-a', ...$flags])
        ->expectsConfirmation($question, 'yes')->assertExitCode(0);
    expect($this->store->read()['merchants']['shop-a'])->toBe(['api_key' => 'fake-secret-a', ...$saved]);
})->with([
    'writes' => [['--write' => true], 'Allow write requests for shop-a?', ['read' => false, 'write' => true]],
    'both' => [['--read' => true, '--write' => true], 'Allow read and write requests for shop-a?', ['read' => true, 'write' => true]],
    'reads while blocking writes' => [['--read' => true, '--no-write' => true], 'Allow read requests for shop-a?', ['read' => true, 'write' => false]],
]);

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

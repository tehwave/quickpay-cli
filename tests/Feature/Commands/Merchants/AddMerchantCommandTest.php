<?php

use App\Console\Terminal\StdinTerminalDetector;
use App\Credentials\MerchantStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('adds a merchant after a private credential check', function (): void {
    Http::fake(['https://api.quickpay.net/ping' => Http::response(['msg' => 'pong', 'scope' => 'merchant', 'version' => 'v10', 'params' => []])]);
    $this->artisan('merchants:add', ['name' => 'My Website'])
        ->expectsQuestion('API key', '  fake-secret-a  ')
        ->expectsOutputToContain('Merchant saved: My Website')
        ->doesntExpectOutputToContain('fake-secret-a')->assertExitCode(0);
    expect($this->store->read()['merchants']['My Website']['api_key'])->toBe('fake-secret-a');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.quickpay.net/ping'
        && $request->method() === 'GET' && $request->header('Authorization') === ['Basic '.base64_encode(':fake-secret-a')]);
});

it('tells upgraders that the old credential file is unused without reading it', function (): void {
    Http::fake(['https://api.quickpay.net/ping' => Http::response(['scope' => 'merchant'])]);
    $legacy = $this->tempDirectory.'/legacy-config.json';
    file_put_contents($legacy, '{"api_key":"fake-legacy-secret"}');
    $this->app->instance(MerchantStore::class, new MerchantStore($this->store->path(), $legacy));
    $this->artisan('merchants:add', ['name' => 'My Website'])
        ->expectsQuestion('API key', 'fake-secret-a')
        ->expectsOutputToContain('no longer uses')
        ->doesntExpectOutputToContain('fake-legacy-secret')->assertExitCode(0);
    expect(file_get_contents($legacy))->toBe('{"api_key":"fake-legacy-secret"}');
});

it('rejects duplicate and invalid names before prompting', function (string $name): void {
    $this->store->add('shop-a', 'fake-original');
    $before = file_get_contents($this->store->path());
    $this->artisan('merchants:add', ['name' => $name])->assertExitCode(1);
    Http::assertNothingSent();
    expect(file_get_contents($this->store->path()))->toBe($before);
})->with(['duplicate' => 'shop-a', 'path' => '../secret', 'leading space' => ' My Website']);

it('replaces a credential only after successful validation', function (): void {
    Http::fake(['https://api.quickpay.net/ping' => Http::response(['msg' => 'pong', 'scope' => 'merchant', 'version' => 'v10', 'params' => []])]);
    $this->store->add('shop-a', 'fake-original');
    $this->artisan('merchants:add', ['name' => 'shop-a', '--replace' => true])
        ->expectsQuestion('API key', 'fake-replacement')->assertExitCode(0);
    expect($this->store->read()['merchants']['shop-a']['api_key'])->toBe('fake-replacement');
});

it('refuses to replace an unsaved merchant before prompting', function (): void {
    $this->store->add('My Website', 'fake-original');
    $before = file_get_contents($this->store->path());
    $this->artisan('merchants:add', ['name' => 'My Webiste', '--replace' => true])
        ->expectsOutputToContain('not saved')->assertExitCode(1);
    Http::assertNothingSent();
    expect(file_get_contents($this->store->path()))->toBe($before);
});

it('preserves the original credential after a rejected replacement', function (): void {
    $this->store->add('shop-a', 'fake-original');
    Http::fake(['*' => Http::response(['message' => 'fake-rejected'], 401)]);
    $this->artisan('merchants:add', ['name' => 'shop-a', '--replace' => true])
        ->expectsQuestion('API key', 'fake-rejected')->doesntExpectOutputToContain('fake-rejected')->assertExitCode(1);
    expect($this->store->read()['merchants']['shop-a']['api_key'])->toBe('fake-original');
});

it('requires interactive input before requesting credentials', function (bool $interactive): void {
    if ($interactive) {
        app()->instance(StdinTerminalDetector::class, new class implements StdinTerminalDetector
        {
            public function isTty(): bool
            {
                return false;
            }
        });
    }
    $this->artisan('merchants:add', ['name' => 'shop-a', '--no-interaction' => ! $interactive])->assertExitCode(1);
    Http::assertNothingSent();
    expect(file_exists($this->store->path()))->toBeFalse();
})->with(['not interactive' => false, 'not a tty' => true]);

it('rejects blank keys and secret labels before HTTP', function (string $name, string $key): void {
    $this->artisan('merchants:add', ['name' => $name])->expectsQuestion('API key', $key)->assertExitCode(1);
    Http::assertNothingSent();
    expect(file_exists($this->store->path()))->toBeFalse();
})->with(['blank' => ['shop-a', ' '], 'label' => ['secret-name', 'secret']]);

it('rejects malformed merchant scope without saving or reflecting secrets', function (mixed $response): void {
    Http::fake(['*' => Http::response($response)]);
    $this->artisan('merchants:add', ['name' => 'shop-a'])->expectsQuestion('API key', 'fake-secret-a')
        ->doesntExpectOutputToContain('fake-secret-a')->assertExitCode(1);
    expect(file_exists($this->store->path()))->toBeFalse();
})->with(['non-json' => 'not json', 'missing' => [[]], 'other scope' => [['scope' => 'user']], 'reflected' => [['scope' => 'fake-secret-a']]]);

it('does not render a reflected version after successful validation', function (): void {
    Http::fake(['*' => Http::response(['scope' => 'merchant', 'version' => 'fake-secret-a'])]);
    $this->artisan('merchants:add', ['name' => 'shop-a'])->expectsQuestion('API key', 'fake-secret-a')
        ->doesntExpectOutputToContain('fake-secret-a')->assertExitCode(0);
});

it('handles network failure without leaking or storing credentials', function (): void {
    Http::fake(fn () => throw new ConnectionException('Network failure fake-secret-a'));
    $this->artisan('merchants:add', ['name' => 'shop-a'])->expectsQuestion('API key', 'fake-secret-a')
        ->expectsOutputToContain('Unable to connect to Quickpay')->doesntExpectOutputToContain('fake-secret-a')->assertExitCode(1);
    expect(file_exists($this->store->path()))->toBeFalse();
});

it('saves a merchant as read-only when asked', function (array $flags, array $saved, ?string $label): void {
    Http::fake(['https://api.quickpay.net/ping' => Http::response(['scope' => 'merchant'])]);
    $command = $this->artisan('merchants:add', ['name' => 'shop-a', ...$flags])
        ->expectsQuestion('API key', 'fake-secret-a');
    $label === null ? $command->doesntExpectOutputToContain('Access:') : $command->expectsOutputToContain('Access: '.$label);
    $command->assertExitCode(0)->run();
    expect($this->store->read()['merchants']['shop-a'])->toBe(['api_key' => 'fake-secret-a', ...$saved]);
    Http::assertSentCount(1);
})->with([
    'default' => [[], ['read_only' => false], null],
    'read-only' => [['--read-only' => true], ['read_only' => true], 'read-only'],
]);

it('keeps a read-only merchant read-only when replacing its credential', function (): void {
    Http::fake(['https://api.quickpay.net/ping' => Http::response(['scope' => 'merchant'])]);
    $this->store->add('shop-a', 'fake-original', readOnly: true);
    $this->artisan('merchants:add', ['name' => 'shop-a', '--replace' => true])
        ->expectsQuestion('API key', 'fake-replacement')->expectsOutputToContain('Access: read-only')->assertExitCode(0);
    expect($this->store->read()['merchants']['shop-a'])->toBe(['api_key' => 'fake-replacement', 'read_only' => true]);
});

it('makes a merchant read-only when replacing its credential with the flag', function (): void {
    Http::fake(['https://api.quickpay.net/ping' => Http::response(['scope' => 'merchant'])]);
    $this->store->add('shop-a', 'fake-original');
    $this->artisan('merchants:add', ['name' => 'shop-a', '--replace' => true, '--read-only' => true])
        ->expectsQuestion('API key', 'fake-replacement')
        ->expectsOutputToContain('Access: read-only')->assertExitCode(0);
    expect($this->store->read()['merchants']['shop-a'])->toBe(['api_key' => 'fake-replacement', 'read_only' => true]);
});

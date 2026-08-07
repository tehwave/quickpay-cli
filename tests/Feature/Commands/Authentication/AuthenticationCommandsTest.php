<?php

use App\Commands\Authentication\AuthCommand;
use App\Credentials\ApiKeyResolver;
use App\Credentials\CredentialFile;
use App\Credentials\EnvironmentVariables;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function () {
    $this->tempDirectory = sys_get_temp_dir().'/quickpay-commands-'.bin2hex(random_bytes(8));
    mkdir($this->tempDirectory, 0700, true);
    $this->credentialPath = $this->tempDirectory.'/config.json';
    $this->originalApiKey = getenv('QUICKPAY_API_KEY');
    putenv('QUICKPAY_API_KEY');
    $file = new CredentialFile($this->credentialPath);
    app()->instance(CredentialFile::class, $file);
    app()->instance(ApiKeyResolver::class, new ApiKeyResolver(new EnvironmentVariables, $file));
});

afterEach(function () {
    if ($this->originalApiKey === false) {
        putenv('QUICKPAY_API_KEY');
    } else {
        putenv('QUICKPAY_API_KEY='.$this->originalApiKey);
    }

    if (is_dir($this->tempDirectory)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->tempDirectory);
    }
});

it('logs in after validating a merchant credential and never displays the key', function () {
    Http::fake(['https://api.quickpay.net/ping' => Http::response([
        'msg' => 'pong',
        'scope' => 'merchant',
        'version' => 'v10',
        'params' => [],
    ])]);

    $this->artisan('login')
        ->expectsQuestion('API key', '  merchant-secret  ')
        ->expectsOutputToContain('Credentials stored')
        ->expectsOutputToContain('Scope: merchant')
        ->expectsOutputToContain('Version: v10')
        ->doesntExpectOutputToContain('merchant-secret')
        ->assertExitCode(0);

    expect(json_decode(file_get_contents($this->credentialPath), true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['api_key' => 'merchant-secret']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.quickpay.net/ping'
        && $request->header('Authorization') === ['Basic '.base64_encode(':merchant-secret')]);
});

it('does not render a reflected credential from the successful login version field', function () {
    Http::fake(['https://api.quickpay.net/ping' => Http::response([
        'scope' => 'merchant',
        'version' => 'reflected-secret',
    ])]);

    $this->artisan('login')
        ->expectsQuestion('API key', 'reflected-secret')
        ->expectsOutputToContain('Version: v10')
        ->doesntExpectOutputToContain('reflected-secret')
        ->assertExitCode(0);
});

it('rejects an empty login credential before making a request', function () {
    Http::fake();

    $this->artisan('login')
        ->expectsQuestion('API key', '   ')
        ->expectsOutputToContain('API key cannot be empty')
        ->assertExitCode(1);

    Http::assertNothingSent();
    expect(file_exists($this->credentialPath))->toBeFalse();
});

it('does not save login credentials rejected by quickpay', function () {
    Http::fake(['https://api.quickpay.net/ping' => Http::response(['message' => 'Invalid API key rejected-secret'], 401)]);

    $this->artisan('login')
        ->expectsQuestion('API key', 'rejected-secret')
        ->expectsOutputToContain('Authentication failed: Invalid API key [redacted]')
        ->doesntExpectOutputToContain('rejected-secret')
        ->assertExitCode(1);

    expect(file_exists($this->credentialPath))->toBeFalse();
});

it('rejects a valid login credential without merchant scope', function () {
    Http::fake(['https://api.quickpay.net/ping' => Http::response(['scope' => 'acquirer', 'version' => 'v10'])]);

    $this->artisan('login')
        ->expectsQuestion('API key', 'non-merchant-secret')
        ->expectsOutputToContain('merchant scope')
        ->doesntExpectOutputToContain('non-merchant-secret')
        ->assertExitCode(1);

    expect(file_exists($this->credentialPath))->toBeFalse();
});

it('handles a login network failure without leaking the credential', function () {
    Http::fake(fn () => throw new ConnectionException('Network failure for network-secret'));

    $this->artisan('login')
        ->expectsQuestion('API key', 'network-secret')
        ->expectsOutputToContain('Unable to connect to Quickpay')
        ->doesntExpectOutputToContain('network-secret')
        ->assertExitCode(1);

    expect(file_exists($this->credentialPath))->toBeFalse();
});

it('shows unauthenticated status and a login hint when no credential exists', function () {
    Http::fake();

    $this->artisan('auth')
        ->expectsOutputToContain('Credential source: none')
        ->expectsOutputToContain('API base URL: https://api.quickpay.net')
        ->expectsOutputToContain('API version: v10')
        ->expectsOutputToContain('Scope: not authenticated')
        ->expectsOutputToContain('quickpay login')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('shows the current authenticated scope without displaying the credential', function () {
    file_put_contents($this->credentialPath, json_encode(['api_key' => 'stored-secret'], JSON_THROW_ON_ERROR));
    Http::fake(['https://api.quickpay.net/ping' => Http::response(['scope' => 'merchant', 'version' => 'v10'])]);

    $this->artisan('auth')
        ->expectsOutputToContain('Credential source: config file')
        ->expectsOutputToContain('API base URL: https://api.quickpay.net')
        ->expectsOutputToContain('API version: v10')
        ->expectsOutputToContain('Scope: merchant')
        ->doesntExpectOutputToContain('stored-secret')
        ->assertExitCode(0);
});

it('does not render a reflected credential from a successful auth scope field', function () {
    file_put_contents($this->credentialPath, json_encode(['api_key' => 'stored-secret'], JSON_THROW_ON_ERROR));
    Http::fake(['https://api.quickpay.net/ping' => Http::response(['scope' => 'stored-secret', 'version' => 'v10'])]);

    $this->artisan('auth')
        ->expectsOutputToContain('Quickpay returned an invalid scope')
        ->doesntExpectOutputToContain('stored-secret')
        ->assertExitCode(1);
});

it('reports auth api errors safely and exits non-zero', function () {
    putenv('QUICKPAY_API_KEY=environment-secret');
    Http::fake(['https://api.quickpay.net/ping' => Http::response(['message' => 'Invalid API key environment-secret'], 401)]);

    $this->artisan('auth')
        ->expectsOutputToContain('Credential source: environment')
        ->expectsOutputToContain('Invalid API key [redacted]')
        ->doesntExpectOutputToContain('environment-secret')
        ->assertExitCode(1);
});

it('routes command failures to stderr', function () {
    putenv('QUICKPAY_API_KEY=stderr-secret');
    Http::fake(['https://api.quickpay.net/ping' => Http::response(['message' => 'Invalid API key'], 401)]);

    $command = new AuthCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $status = $tester->execute([], ['capture_stderr_separately' => true]);

    expect($status)->toBe(1)
        ->and($tester->getErrorOutput())->toContain('Authentication check failed: Invalid API key')
        ->and($tester->getDisplay())->not->toContain('Authentication check failed');
});

it('reports auth network errors safely and exits non-zero', function () {
    putenv('QUICKPAY_API_KEY=environment-secret');
    Http::fake(fn () => throw new ConnectionException('Failure for environment-secret'));

    $this->artisan('auth')
        ->expectsOutputToContain('Unable to connect to Quickpay')
        ->doesntExpectOutputToContain('environment-secret')
        ->assertExitCode(1);
});

it('logs out by deleting the stored config credential', function () {
    file_put_contents($this->credentialPath, json_encode(['api_key' => 'stored-secret'], JSON_THROW_ON_ERROR));
    Http::fake();

    $this->artisan('logout')
        ->expectsOutputToContain('Stored credentials removed')
        ->doesntExpectOutputToContain('stored-secret')
        ->assertExitCode(0);

    expect(file_exists($this->credentialPath))->toBeFalse();
    Http::assertNothingSent();
});

it('treats logout without stored credentials as a successful no-op', function () {
    Http::fake();

    $this->artisan('logout')
        ->expectsOutputToContain('No stored credentials found')
        ->assertExitCode(0);
});

it('explains that an environment credential remains active after logout', function () {
    file_put_contents($this->credentialPath, json_encode(['api_key' => 'stored-secret'], JSON_THROW_ON_ERROR));
    putenv('QUICKPAY_API_KEY=environment-secret');
    Http::fake();

    $this->artisan('logout')
        ->expectsOutputToContain('Stored credentials removed')
        ->expectsOutputToContain('QUICKPAY_API_KEY is still active')
        ->doesntExpectOutputToContain('environment-secret')
        ->doesntExpectOutputToContain('stored-secret')
        ->assertExitCode(0);

    expect(file_exists($this->credentialPath))->toBeFalse();
});

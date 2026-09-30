<?php

use App\Commands\Merchants\ActiveMerchantCommand;
use App\Commands\Merchants\ListMerchantsCommand;
use App\Console\BaseCommand;
use App\Console\Terminal\StdinTerminalDetector;
use App\Credentials\ApiKeyResolver;
use App\Credentials\EnvironmentVariables;
use App\Credentials\MerchantStore;
use App\Credentials\ProjectMerchantFile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Tester\CommandTester;

/** @param class-string<BaseCommand> $class */
function merchantCommandResult(string $class, array $input = []): array
{
    $command = new $class;
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $exit = $tester->execute($input, ['capture_stderr_separately' => true]);

    return [$exit, $tester->getDisplay(), $tester->getErrorOutput()];
}

beforeEach(function (): void {
    $this->tempDirectory = sys_get_temp_dir().'/quickpay-merchant-command-'.bin2hex(random_bytes(8));
    mkdir($this->tempDirectory.'/project/nested', 0700, true);
    $this->originalDirectory = getcwd();
    $this->originalApiKey = getenv('QUICKPAY_API_KEY');
    putenv('QUICKPAY_API_KEY');
    chdir($this->tempDirectory.'/project');
    $this->store = new MerchantStore($this->tempDirectory.'/config.json');
    $this->project = new ProjectMerchantFile;
    app()->instance(MerchantStore::class, $this->store);
    app()->instance(ProjectMerchantFile::class, $this->project);
    app()->instance(ApiKeyResolver::class, new ApiKeyResolver(new EnvironmentVariables, $this->store, $this->project));
    app()->instance(StdinTerminalDetector::class, new class implements StdinTerminalDetector
    {
        public function isTty(): bool
        {
            return true;
        }
    });
    Http::preventStrayRequests();
});

afterEach(function (): void {
    chdir($this->originalDirectory);
    $this->originalApiKey === false ? putenv('QUICKPAY_API_KEY') : putenv('QUICKPAY_API_KEY='.$this->originalApiKey);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->tempDirectory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->tempDirectory);
});

it('adds a merchant after a private credential check', function (): void {
    Http::fake(['https://api.quickpay.net/ping' => Http::response(['msg' => 'pong', 'scope' => 'merchant', 'version' => 'v10', 'params' => []])]);
    $this->artisan('merchants:add', ['name' => 'shop-a'])
        ->expectsQuestion('API key', '  fake-secret-a  ')
        ->expectsOutputToContain('Merchant saved: shop-a')
        ->doesntExpectOutputToContain('fake-secret-a')->assertExitCode(0);
    expect($this->store->read()['merchants']['shop-a']['api_key'])->toBe('fake-secret-a');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.quickpay.net/ping'
        && $request->method() === 'GET' && $request->header('Authorization') === ['Basic '.base64_encode(':fake-secret-a')]);
});

it('rejects duplicate and invalid names before prompting', function (string $name): void {
    $this->store->add('shop-a', 'fake-original');
    $before = file_get_contents($this->store->path());
    $this->artisan('merchants:add', ['name' => $name])->assertExitCode(1);
    Http::assertNothingSent();
    expect(file_get_contents($this->store->path()))->toBe($before);
})->with(['duplicate' => 'shop-a', 'path' => '../secret', 'uppercase' => 'Shop-a']);

it('replaces a credential only after successful validation', function (): void {
    Http::fake(['https://api.quickpay.net/ping' => Http::response(['msg' => 'pong', 'scope' => 'merchant', 'version' => 'v10', 'params' => []])]);
    $this->store->add('shop-a', 'fake-original');
    $this->artisan('merchants:add', ['name' => 'shop-a', '--replace' => true])
        ->expectsQuestion('API key', 'fake-replacement')->assertExitCode(0);
    expect($this->store->read()['merchants']['shop-a']['api_key'])->toBe('fake-replacement');
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

it('sanitizes authentication errors on stderr', function (string $form): void {
    $this->store->add('shop-a', 'fake/secret');
    Http::fake(['*' => Http::response(['message' => 'Rejected '.$form], 401)]);
    [$exit, $stdout, $stderr] = merchantCommandResult(ActiveMerchantCommand::class, ['--check' => true, '--json' => true]);
    expect($exit)->toBe(1)->and($stderr)->toContain('[redacted]')
        ->and($stdout.$stderr)->not->toContain('fake/secret')->not->toContain($form);
    expect(json_decode($stdout, true, flags: JSON_THROW_ON_ERROR)['authenticated'])->toBeFalse();
})->with(['raw' => 'fake/secret', 'basic' => 'Basic '.base64_encode(':fake/secret'), 'url encoded' => 'fake%2Fsecret']);

it('handles network failure without leaking or storing credentials', function (): void {
    Http::fake(fn () => throw new ConnectionException('Network failure fake-secret-a'));
    $this->artisan('merchants:add', ['name' => 'shop-a'])->expectsQuestion('API key', 'fake-secret-a')
        ->expectsOutputToContain('Unable to connect to Quickpay')->doesntExpectOutputToContain('fake-secret-a')->assertExitCode(1);
    expect(file_exists($this->store->path()))->toBeFalse();
});

it('lists only sorted names and the global default', function (): void {
    $this->store->add('shop-b', 'fake-secret-b');
    $this->store->add('shop-a', 'fake-secret-a');
    [$exit, $output, $errors] = merchantCommandResult(ListMerchantsCommand::class, ['--json' => true]);
    expect($exit)->toBe(0)->and($errors)->toBe('')->and(json_decode($output, true, flags: JSON_THROW_ON_ERROR))
        ->toBe([['name' => 'shop-a', 'default' => false], ['name' => 'shop-b', 'default' => true]]);
    Http::assertNothingSent();
});

it('lists an empty store without contacting Quickpay', function (): void {
    [$exit, $output] = merchantCommandResult(ListMerchantsCommand::class, ['--json' => true]);
    expect($exit)->toBe(0)->and($output)->toBe('[]');
    Http::assertNothingSent();
});

it('selects saved merchants globally without re-entering credentials', function (): void {
    $this->store->add('shop-a', 'fake-secret-a');
    $this->store->add('shop-b', 'fake-secret-b');
    $this->artisan('merchants:select', ['name' => 'shop-b'])->assertExitCode(0);
    expect($this->store->read()['default_merchant'])->toBe('shop-b');
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
    $this->artisan('merchants:select')->expectsChoice('Select merchant', $selection, ['shop-a', 'shop-b', 'Cancel'])->assertExitCode(0);
    expect($this->store->read()['default_merchant'])->toBe($selection === 'Cancel' ? 'shop-a' : 'shop-b');
    Http::assertNothingSent();
})->with(['selection' => 'shop-b', 'cancel' => 'Cancel']);

it('selects a project merchant without changing the global default', function (): void {
    $this->store->add('shop-a', 'fake-secret-a');
    $this->store->add('shop-b', 'fake-secret-b');
    $this->artisan('merchants:select', ['name' => 'shop-b', '--local' => true])->assertExitCode(0);
    chdir($this->tempDirectory.'/project/nested');
    [$exit, $output] = merchantCommandResult(ActiveMerchantCommand::class, ['--json' => true]);
    expect($exit)->toBe(0)->and(json_decode($output, true)['merchant'])->toBe('shop-b')
        ->and($this->store->read()['default_merchant'])->toBe('shop-a');
    mkdir($this->tempDirectory.'/other');
    chdir($this->tempDirectory.'/other');
    [$exit, $output] = merchantCommandResult(ActiveMerchantCommand::class, ['--json' => true]);
    expect(json_decode($output, true)['merchant'])->toBe('shop-a');
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

it('reports configuration without claiming unverified authentication', function (): void {
    $this->store->add('shop-a', 'fake-secret-a');
    [$exit, $output, $errors] = merchantCommandResult(ActiveMerchantCommand::class, ['--json' => true]);
    expect($exit)->toBe(0)->and($errors)->toBe('')->and(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toBe([
        'merchant' => 'shop-a', 'selection_source' => 'default', 'credential_source' => 'config file',
        'configured' => true, 'checked' => false, 'authenticated' => null,
        'api_base_url' => 'https://api.quickpay.net', 'api_version' => 'v10', 'scope' => null,
    ]);
    $this->artisan('merchants:active')->expectsOutputToContain('shop-a')->expectsOutputToContain('not checked')->assertExitCode(0);
    Http::assertNothingSent();
});

it('reports absent credentials and fails only a requested readiness check', function (): void {
    [$exit, $output] = merchantCommandResult(ActiveMerchantCommand::class, ['--json' => true]);
    expect($exit)->toBe(0)->and(json_decode($output, true)['configured'])->toBeFalse();
    [$exit, $output, $errors] = merchantCommandResult(ActiveMerchantCommand::class, ['--json' => true, '--check' => true]);
    expect($exit)->toBe(1)->and($errors)->toContain('merchants:add')
        ->and(json_decode($output, true)['authenticated'])->toBeFalse();
    Http::assertNothingSent();
});

it('reports environment-only credentials without inferring a merchant label', function (): void {
    putenv('QUICKPAY_API_KEY=fake-environment');
    [$exit, $output] = merchantCommandResult(ActiveMerchantCommand::class, ['--json' => true]);
    $data = json_decode($output, true);
    expect($exit)->toBe(0)->and($data['merchant'])->toBeNull()->and($data['selection_source'])->toBe('environment')
        ->and($data['credential_source'])->toBe('environment')->and($data['authenticated'])->toBeNull();
    Http::assertNothingSent();
});

it('checks the same explicitly selected key and keeps JSON stdout clean', function (): void {
    Http::fake(['https://api.quickpay.net/ping' => Http::response(['msg' => 'pong', 'scope' => 'merchant', 'version' => 'v10', 'params' => []])]);
    $this->store->add('shop-a', 'fake-secret-a');
    $this->store->add('shop-b', 'fake-secret-b');
    [$exit, $output, $errors] = merchantCommandResult(ActiveMerchantCommand::class, ['--merchant' => 'shop-b', '--check' => true, '--json' => true]);
    $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect($exit)->toBe(0)->and($errors)->toBe('')->and($data['authenticated'])->toBeTrue()
        ->and($data['checked'])->toBeTrue()->and($data['scope'])->toBe('merchant')->and($data['merchant'])->toBe('shop-b');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Basic '.base64_encode(':fake-secret-b')]);
});

it('fails invalid selection before writing status or making requests', function (bool $environment): void {
    $this->store->add('shop-a', 'fake-secret-a');
    if ($environment) {
        putenv('QUICKPAY_API_KEY=fake-environment');
    }
    [$exit, $output, $errors] = merchantCommandResult(ActiveMerchantCommand::class, ['--merchant' => $environment ? 'shop-a' : 'shop-missing', '--json' => true]);
    expect($exit)->toBe(1)->and($output)->toBe('')->and($errors)->not->toBe('');
    Http::assertNothingSent();
})->with(['unknown' => false, 'conflict' => true]);

it('removes only the named credential without choosing another default', function (): void {
    $this->store->add('shop-a', 'fake-secret-a');
    $this->store->add('shop-b', 'fake-secret-b');
    $this->artisan('merchants:remove', ['name' => 'shop-a'])->assertExitCode(0);
    expect($this->store->read())->toBe(['default_merchant' => null, 'merchants' => ['shop-b' => ['api_key' => 'fake-secret-b']]]);
    $this->artisan('merchants:remove', ['name' => 'shop-missing'])->assertExitCode(1);
    $this->artisan('merchants:remove', ['name' => 'shop-b'])->assertExitCode(0);
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

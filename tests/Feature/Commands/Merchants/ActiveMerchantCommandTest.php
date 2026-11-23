<?php

use App\Commands\Merchants\ActiveMerchantCommand;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('sanitizes authentication errors on stderr', function (string $form): void {
    $this->store->add('shop-a', 'fake/secret');
    Http::fake(['*' => Http::response(['message' => 'Rejected '.$form], 401)]);
    [$exit, $stdout, $stderr] = merchantCommandResult(ActiveMerchantCommand::class, ['--check' => true, '--json' => true]);
    expect($exit)->toBe(1)->and($stderr)->toContain('[redacted]')
        ->and($stdout.$stderr)->not->toContain('fake/secret')->not->toContain($form);
    expect(json_decode($stdout, true, flags: JSON_THROW_ON_ERROR)['authenticated'])->toBeFalse();
})->with(['raw' => 'fake/secret', 'basic' => 'Basic '.base64_encode(':fake/secret'), 'url encoded' => 'fake%2Fsecret']);

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
    $this->store->add('My Other Website', 'fake-secret-a');
    $this->store->add('My Website', 'fake-secret-b');
    [$exit, $output, $errors] = merchantCommandResult(ActiveMerchantCommand::class, ['--merchant' => 'My Website', '--check' => true, '--json' => true]);
    $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect($exit)->toBe(0)->and($errors)->toBe('')->and($data['authenticated'])->toBeTrue()
        ->and($data['checked'])->toBeTrue()->and($data['scope'])->toBe('merchant')->and($data['merchant'])->toBe('My Website');
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

it('rejects ambiguous configuration before checking API access', function (bool $project): void {
    $this->store->add('shop-a', 'fake-first');
    $this->store->add('shop-b', 'fake-last');
    $path = $project ? getcwd().'/.quickpay-cli.json' : $this->store->path();
    $contents = $project
        ? '{"merchant":"shop-a","merch\\u0061nt":"shop-b"}'
        : '{"default_merchant":"shop-a","merchants":{"shop-a":{"api_key":"fake-first"},"sh\\u006fp-a":{"api_key":"fake-last"}}}';
    file_put_contents($path, $contents);
    Http::fake(['*' => Http::response(['scope' => 'merchant'])]);
    [$exit, $output, $errors] = merchantCommandResult(ActiveMerchantCommand::class, ['--check' => true, '--json' => true]);
    expect($exit)->toBe(1)->and($output)->toBe('')->and($errors)->not->toBe('')
        ->and(file_get_contents($path))->toBe($contents);
    Http::assertNothingSent();
})->with(['store' => false, 'project' => true]);

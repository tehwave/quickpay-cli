<?php

use App\Credentials\ApiKeyResolver;
use App\Credentials\EnvironmentVariables;
use App\Credentials\Exceptions\CredentialException;
use App\Credentials\MerchantStore;
use App\Credentials\ProjectMerchantFile;

beforeEach(function (): void {
    $this->tempDirectory = sys_get_temp_dir().'/quickpay-resolver-'.bin2hex(random_bytes(8));
    mkdir($this->tempDirectory, 0700, true);
    $this->originalApiKey = getenv('QUICKPAY_API_KEY');
    putenv('QUICKPAY_API_KEY');
    $this->store = new MerchantStore($this->tempDirectory.'/config.json');
    $this->project = new ProjectMerchantFile($this->tempDirectory);
    $this->resolver = new ApiKeyResolver(new EnvironmentVariables, $this->store, $this->project);
});

afterEach(function (): void {
    $this->originalApiKey === false ? putenv('QUICKPAY_API_KEY') : putenv('QUICKPAY_API_KEY='.$this->originalApiKey);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->tempDirectory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->tempDirectory);
});

it('returns environment context without reading malformed saved storage', function (): void {
    file_put_contents($this->store->path(), '{malformed');
    putenv('QUICKPAY_API_KEY=fake-environment');
    $key = $this->resolver->resolve();
    expect($key?->value())->toBe('fake-environment')->and($key?->source())->toBe('environment')
        ->and($key?->merchant())->toBeNull()->and($key?->selectionSource())->toBe('environment');
});

it('uses the default only without explicit or project selection', function (): void {
    $this->store->add('shop-a', 'fake-secret-a');
    $this->store->add('shop-b', 'fake-secret-b');
    expect($this->resolver->resolve()?->merchant())->toBe('shop-a')
        ->and($this->resolver->resolve()?->selectionSource())->toBe('default');
    $this->project->save('shop-b');
    $key = $this->resolver->resolve();
    expect($key?->value())->toBe('fake-secret-b')->and($key?->selectionSource())->toBe('project');
    $key = $this->resolver->resolve('shop-a');
    expect($key?->merchant())->toBe('shop-a')->and($key?->source())->toBe('config file')
        ->and($key?->selectionSource())->toBe('explicit');
});

it('bypasses malformed projects for an explicit selection', function (): void {
    $this->store->add('shop-a', 'fake-secret-a');
    file_put_contents($this->tempDirectory.'/.quickpay-cli.json', '{broken');
    expect($this->resolver->resolve('shop-a')?->value())->toBe('fake-secret-a');
    expect(fn () => $this->resolver->resolve())->toThrow(CredentialException::class);
});

it('rejects stale project and unknown explicit selections', function (): void {
    $this->store->add('shop-a', 'fake-secret-a');
    $this->project->save('shop-b');
    expect(fn () => $this->resolver->resolve())->toThrow(CredentialException::class);
    expect(fn () => $this->resolver->resolve('shop-b'))->toThrow(CredentialException::class);
});

it('rejects environment and explicit selection together', function (): void {
    putenv('QUICKPAY_API_KEY=fake-environment');
    file_put_contents($this->store->path(), '{malformed');
    expect(fn () => $this->resolver->resolve('shop-a'))->toThrow(CredentialException::class, 'QUICKPAY_API_KEY');
});

it('rejects environment and project selection together', function (): void {
    putenv('QUICKPAY_API_KEY=fake-environment');
    $this->project->save('shop-a');
    expect(fn () => $this->resolver->resolve())->toThrow(CredentialException::class, 'QUICKPAY_API_KEY');
});

it('ignores an empty environment override', function (): void {
    putenv('QUICKPAY_API_KEY=');
    $this->store->add('shop-a', 'fake-secret-a');
    expect($this->resolver->resolve()?->source())->toBe('config file');
});

it('returns no credential for an absent or null default', function (): void {
    expect($this->resolver->resolve())->toBeNull();
    $this->store->add('shop-a', 'fake-secret-a');
    $this->store->add('shop-b', 'fake-secret-b');
    $this->store->remove('shop-a');
    expect($this->resolver->resolve())->toBeNull();
});

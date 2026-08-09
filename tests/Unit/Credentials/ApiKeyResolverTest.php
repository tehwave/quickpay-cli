<?php

use App\Credentials\ApiKeyResolver;
use App\Credentials\CredentialFile;
use App\Credentials\EnvironmentVariables;

beforeEach(function (): void {
    $this->credentialPath = sys_get_temp_dir().'/quickpay-resolver-'.bin2hex(random_bytes(8)).'.json';
    $this->originalApiKey = getenv('QUICKPAY_API_KEY');
    putenv('QUICKPAY_API_KEY');
});

afterEach(function (): void {
    $this->originalApiKey === false
        ? putenv('QUICKPAY_API_KEY')
        : putenv('QUICKPAY_API_KEY='.$this->originalApiKey);

    if (is_file($this->credentialPath)) {
        unlink($this->credentialPath);
    }
});

it('prefers a non-empty environment key without reading malformed storage', function (): void {
    file_put_contents($this->credentialPath, '{malformed');
    putenv('QUICKPAY_API_KEY=environment-key');

    $key = (new ApiKeyResolver(
        new EnvironmentVariables,
        new CredentialFile($this->credentialPath),
    ))->resolve();

    expect($key?->value())->toBe('environment-key')
        ->and($key?->source())->toBe('environment');
});

it('falls back to a stored key and reports its source', function (): void {
    file_put_contents($this->credentialPath, json_encode(['api_key' => 'stored-key'], JSON_THROW_ON_ERROR));
    putenv('QUICKPAY_API_KEY=');

    $key = (new ApiKeyResolver(
        new EnvironmentVariables,
        new CredentialFile($this->credentialPath),
    ))->resolve();

    expect($key?->value())->toBe('stored-key')
        ->and($key?->source())->toBe('config file');
});

it('returns null when neither credential source is present', function (): void {
    expect((new ApiKeyResolver(
        new EnvironmentVariables,
        new CredentialFile($this->credentialPath),
    ))->resolve())->toBeNull();
});

<?php

use App\Credentials\CredentialStore;
use App\Credentials\Exceptions\CredentialStoreException;

beforeEach(function () {
    $this->tempDirectory = sys_get_temp_dir().'/quickpay-cli-'.bin2hex(random_bytes(8));
    mkdir($this->tempDirectory, 0700, true);
    $this->originalApiKey = getenv('QUICKPAY_API_KEY');
    putenv('QUICKPAY_API_KEY');
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

it('prefers a non-empty environment credential over the config file', function () {
    $path = $this->tempDirectory.'/config.json';
    file_put_contents($path, '{malformed stored config');
    putenv('QUICKPAY_API_KEY=environment-key');

    $store = new CredentialStore(configPath: $path);

    expect($store->apiKey())->toBe('environment-key')
        ->and($store->source())->toBe('environment');
});

it('uses the config credential when the environment credential is empty', function () {
    $path = $this->tempDirectory.'/config.json';
    file_put_contents($path, json_encode(['api_key' => 'stored-key'], JSON_THROW_ON_ERROR));
    putenv('QUICKPAY_API_KEY=');

    $store = new CredentialStore(configPath: $path);

    expect($store->apiKey())->toBe('stored-key')
        ->and($store->source())->toBe('config file');
});

it('reports none when no credential exists', function () {
    $store = new CredentialStore(configPath: $this->tempDirectory.'/missing.json');

    expect($store->apiKey())->toBeNull()
        ->and($store->source())->toBe('none');
});

it('resolves the default path from a home override', function () {
    $store = new CredentialStore(home: $this->tempDirectory);

    expect($store->configPath())->toBe($this->tempDirectory.'/.config/quickpay/config.json');
});

it('falls back from HOME to USERPROFILE when resolving the default path', function () {
    $originalHome = getenv('HOME');
    $originalUserProfile = getenv('USERPROFILE');

    try {
        putenv('HOME=');
        putenv('USERPROFILE='.$this->tempDirectory);

        $store = new CredentialStore;

        expect($store->configPath())->toBe($this->tempDirectory.'/.config/quickpay/config.json');
    } finally {
        $originalHome === false ? putenv('HOME') : putenv('HOME='.$originalHome);
        $originalUserProfile === false ? putenv('USERPROFILE') : putenv('USERPROFILE='.$originalUserProfile);
    }
});

it('saves credentials with restrictive directory and file modes', function () {
    $path = $this->tempDirectory.'/nested/quickpay/config.json';
    mkdir(dirname($path), 0777, true);
    file_put_contents($path, '{}');
    chmod($path, 0666);
    chmod(dirname($path), 0777);

    $store = new CredentialStore(configPath: $path);
    $store->save('stored-secret');
    clearstatcache(true, $path);

    expect(fileperms(dirname($path)) & 0777)->toBe(0700)
        ->and(fileperms($path) & 0777)->toBe(0600)
        ->and(json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR))->toBe(['api_key' => 'stored-secret']);
});

it('atomically replaces an existing credential without leaving temp files', function () {
    $path = $this->tempDirectory.'/config.json';
    file_put_contents($path, json_encode(['api_key' => 'old-key'], JSON_THROW_ON_ERROR));
    $beforeInode = fileinode($path);

    $store = new CredentialStore(configPath: $path);
    $store->save('new-key');
    clearstatcache(true, $path);

    expect(fileinode($path))->not->toBe($beforeInode)
        ->and(glob($this->tempDirectory.'/.config.json.*'))->toBe([])
        ->and(json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR))->toBe(['api_key' => 'new-key']);
});

it('cleans up its temp file when atomic replacement fails', function () {
    $path = $this->tempDirectory.'/config.json';
    mkdir($path);
    $store = new CredentialStore(configPath: $path);

    expect(fn () => $store->save('never-show-this-secret'))
        ->toThrow(CredentialStoreException::class, 'Unable to store Quickpay credentials');
    expect(glob($this->tempDirectory.'/.config.json.*'))->toBe([]);
});

it('rejects malformed or structurally invalid config safely', function (string $contents) {
    $path = $this->tempDirectory.'/config.json';
    file_put_contents($path, $contents);
    $store = new CredentialStore(configPath: $path);

    expect(fn () => $store->apiKey())
        ->toThrow(CredentialStoreException::class, 'Quickpay credential file is invalid');
})->with([
    'malformed json' => '{"api_key":',
    'missing key' => '{"other":"value"}',
    'non-string key' => '{"api_key":123}',
    'empty key' => '{"api_key":""}',
]);

it('forgets only the config file while an environment credential remains active', function () {
    $path = $this->tempDirectory.'/config.json';
    file_put_contents($path, json_encode(['api_key' => 'stored-key'], JSON_THROW_ON_ERROR));
    putenv('QUICKPAY_API_KEY=environment-key');
    $store = new CredentialStore(configPath: $path);

    expect($store->forgetStored())->toBeTrue()
        ->and(file_exists($path))->toBeFalse()
        ->and($store->apiKey())->toBe('environment-key')
        ->and($store->source())->toBe('environment');
});

it('reports a no-op when there is no stored credential to forget', function () {
    $store = new CredentialStore(configPath: $this->tempDirectory.'/missing.json');

    expect($store->forgetStored())->toBeFalse();
});

it('never includes the credential in persistence errors', function () {
    $path = $this->tempDirectory.'/config.json';
    mkdir($path);
    $store = new CredentialStore(configPath: $path);

    try {
        $store->save('highly-sensitive-key');
        $this->fail('Expected credential storage to fail.');
    } catch (CredentialStoreException $exception) {
        expect($exception->getMessage())->not->toContain('highly-sensitive-key');
    }
});

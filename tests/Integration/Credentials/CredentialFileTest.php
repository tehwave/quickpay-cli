<?php

use App\Credentials\CredentialFile;
use App\Credentials\Exceptions\CredentialException;

beforeEach(function (): void {
    $this->tempDirectory = sys_get_temp_dir().'/quickpay-file-'.bin2hex(random_bytes(8));
    mkdir($this->tempDirectory, 0700, true);
    $this->path = $this->tempDirectory.'/nested/config.json';
});

afterEach(function (): void {
    if (! is_dir($this->tempDirectory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->tempDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($this->tempDirectory);
});

it('atomically persists and reads a credential with restrictive modes', function (): void {
    $file = new CredentialFile($this->path);
    $file->save('stored-secret');
    clearstatcache(true, $this->path);

    expect($file->read())->toBe('stored-secret')
        ->and(fileperms(dirname($this->path)) & 0777)->toBe(0700)
        ->and(fileperms($this->path) & 0777)->toBe(0600)
        ->and(glob(dirname($this->path).'/.config.json.*'))->toBe([]);
});

it('rejects malformed credential files', function (string $contents): void {
    mkdir(dirname($this->path), 0700, true);
    file_put_contents($this->path, $contents);

    expect(fn (): ?string => (new CredentialFile($this->path))->read())
        ->toThrow(CredentialException::class, 'Quickpay credential file is invalid');
})->with([
    '{"api_key":',
    '{"other":"value"}',
    '{"api_key":123}',
    '{"api_key":""}',
]);

it('forgets only the stored credential', function (): void {
    $file = new CredentialFile($this->path);
    $file->save('stored-key');

    expect($file->forget())->toBeTrue()
        ->and($file->forget())->toBeFalse();
});

it('never includes the credential in persistence errors', function (): void {
    mkdir($this->path, 0700, true);

    try {
        (new CredentialFile($this->path))->save('highly-sensitive-key');
        $this->fail('Expected credential storage to fail.');
    } catch (CredentialException $exception) {
        expect($exception->getMessage())->not->toContain('highly-sensitive-key');
    }
});

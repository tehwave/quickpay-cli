<?php

use App\Credentials\Exceptions\CredentialException;
use App\Credentials\ProjectMerchantFile;

beforeEach(function (): void {
    $this->tempDirectory = sys_get_temp_dir().'/quickpay-project-'.bin2hex(random_bytes(8));
    mkdir($this->tempDirectory.'/nested', 0700, true);
    $this->tempDirectory = realpath($this->tempDirectory);
});

afterEach(function (): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->tempDirectory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->tempDirectory);
});

it('resolves the nearest binding from nested directories', function (): void {
    $parent = new ProjectMerchantFile($this->tempDirectory);
    $child = new ProjectMerchantFile($this->tempDirectory.'/nested');
    $parent->save('shop-a');
    expect($child->find())->toBe('shop-a');
    $child->save('shop-b');
    expect($child->find())->toBe('shop-b')
        ->and($parent->find())->toBe('shop-a')
        ->and(json_decode(file_get_contents($this->tempDirectory.'/nested/.quickpay-cli.json'), true))->toBe(['merchant' => 'shop-b'])
        ->and(glob($this->tempDirectory.'/nested/..quickpay-cli.json.*'))->toBe([]);
});

it('rejects malformed nearest bindings without falling back or overwriting', function (string $contents): void {
    (new ProjectMerchantFile($this->tempDirectory))->save('shop-a');
    $path = $this->tempDirectory.'/nested/.quickpay-cli.json';
    file_put_contents($path, $contents);
    $file = new ProjectMerchantFile($this->tempDirectory.'/nested');
    expect(fn () => $file->find())->toThrow(CredentialException::class, $path);
    expect(fn () => $file->save('shop-b'))->toThrow(CredentialException::class);
    expect(file_get_contents($path))->toBe($contents);
})->with([
    'broken' => '{broken', 'list' => '[]', 'numeric' => '{"merchant":123}', 'missing' => '{}',
    'secret' => '{"merchant":"shop-a","api_key":"fake-secret"}',
    'url' => '{"merchant":"shop-a","url":"https://other.test"}',
    'headers' => '{"merchant":"shop-a","headers":{}}', 'invalid name' => '{"merchant":" My Website"}',
    'duplicate selector' => '{"merchant":"shop-a","merchant":"shop-b"}',
    'escaped duplicate selector' => '{"merchant":"shop-a","merch\\u0061nt":"shop-b"}',
]);

it('names a repeated property as the possible cause of an unreadable binding', function (): void {
    file_put_contents($this->tempDirectory.'/.quickpay-cli.json', '{"merchant":"shop-a","merchant":"shop-b"}');
    expect(fn () => (new ProjectMerchantFile($this->tempDirectory))->find())
        ->toThrow(CredentialException::class, 'repeats a property');
});

it('rejects an ancestor binding owned by another user', function (): void {
    (new ProjectMerchantFile($this->tempDirectory))->save('shop-a');
    $otherUser = fileowner($this->tempDirectory.'/.quickpay-cli.json') + 1;
    expect(fn () => (new ProjectMerchantFile($this->tempDirectory.'/nested', $otherUser))->find())
        ->toThrow(CredentialException::class, 'not owned by the current user');
});

it('rejects a binding symlink owned by another user even when its target matches', function (): void {
    // A root-owned target stands in for "this user's file": the link itself is
    // owned by the test user, so only an lstat owner check can reject it.
    $target = '/etc/hosts';
    symlink($target, $this->tempDirectory.'/.quickpay-cli.json');
    expect(fn () => (new ProjectMerchantFile($this->tempDirectory.'/nested', fileowner($target)))->find())
        ->toThrow(CredentialException::class, 'not owned by the current user');
})->skip(fn (): bool => ! is_file('/etc/hosts')
    || ! function_exists('posix_geteuid')
    || fileowner('/etc/hosts') === posix_geteuid(), 'needs a readable file owned by another user');

it('accepts a binding symlink owned by the current user', function (): void {
    mkdir($this->tempDirectory.'/real');
    (new ProjectMerchantFile($this->tempDirectory.'/real'))->save('shop-a');
    symlink($this->tempDirectory.'/real/.quickpay-cli.json', $this->tempDirectory.'/.quickpay-cli.json');
    expect((new ProjectMerchantFile($this->tempDirectory.'/nested'))->find())->toBe('shop-a');
});

it('rejects a directory masquerading as a project file', function (): void {
    mkdir($this->tempDirectory.'/nested/.quickpay-cli.json');
    expect(fn () => (new ProjectMerchantFile($this->tempDirectory.'/nested'))->find())->toThrow(CredentialException::class);
});

it('returns no selection when no binding exists', function (): void {
    expect((new ProjectMerchantFile($this->tempDirectory))->find())->toBeNull();
});

it('finds the current user without the posix extension', function (): void {
    $probe = $this->tempDirectory.'/probe';
    touch($probe);
    expect(ProjectMerchantFile::temporaryFileOwner())->toBe(fileowner($probe))
        ->and(ProjectMerchantFile::currentUser())->toBe(fileowner($probe));
});

it('checks the owner before reading a binding', function (): void {
    // An unreadable file shows the order: reading first would fail with
    // "cannot be read" before the owner was ever checked.
    $path = $this->tempDirectory.'/.quickpay-cli.json';
    file_put_contents($path, '{"merchant":"shop-a"}');
    chmod($path, 0000);
    $otherUser = fileowner($path) + 1;
    expect(fn () => (new ProjectMerchantFile($this->tempDirectory.'/nested', $otherUser))->find())
        ->toThrow(CredentialException::class, 'not owned by the current user');
})->skip(fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root can read any file');

it('refuses an oversized binding without parsing it', function (): void {
    file_put_contents($this->tempDirectory.'/.quickpay-cli.json', '{"merchant":"shop-a"}'.str_repeat(' ', 4096));
    expect(fn () => (new ProjectMerchantFile($this->tempDirectory.'/nested'))->find())
        ->toThrow(CredentialException::class, 'too large to hold only a merchant name');
});

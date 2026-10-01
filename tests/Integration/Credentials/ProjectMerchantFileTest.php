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
    expect($child->find())->toBe(['merchant' => 'shop-a', 'path' => $this->tempDirectory.'/.quickpay-cli.json']);
    $child->save('shop-b');
    expect($child->find())->toBe(['merchant' => 'shop-b', 'path' => $this->tempDirectory.'/nested/.quickpay-cli.json'])
        ->and($parent->find()['merchant'])->toBe('shop-a')
        ->and(json_decode(file_get_contents($this->tempDirectory.'/nested/.quickpay-cli.json'), true))->toBe(['merchant' => 'shop-b'])
        ->and(glob($this->tempDirectory.'/nested/..quickpay-cli.json.*'))->toBe([]);
});

it('rejects malformed nearest bindings without falling back or overwriting', function (string $contents): void {
    (new ProjectMerchantFile($this->tempDirectory))->save('shop-a');
    $path = $this->tempDirectory.'/nested/.quickpay-cli.json';
    file_put_contents($path, $contents);
    $file = new ProjectMerchantFile($this->tempDirectory.'/nested');
    expect(fn () => $file->find())->toThrow(CredentialException::class);
    expect(fn () => $file->save('shop-b'))->toThrow(CredentialException::class);
    expect(file_get_contents($path))->toBe($contents);
})->with([
    'broken' => '{broken', 'list' => '[]', 'numeric' => '{"merchant":123}', 'missing' => '{}',
    'secret' => '{"merchant":"shop-a","api_key":"fake-secret"}',
    'url' => '{"merchant":"shop-a","url":"https://other.test"}',
    'headers' => '{"merchant":"shop-a","headers":{}}', 'invalid name' => '{"merchant":"Shop-a"}',
    'duplicate selector' => '{"merchant":"shop-a","merchant":"shop-b"}',
    'escaped duplicate selector' => '{"merchant":"shop-a","merch\\u0061nt":"shop-b"}',
]);

it('rejects a directory masquerading as a project file', function (): void {
    mkdir($this->tempDirectory.'/nested/.quickpay-cli.json');
    expect(fn () => (new ProjectMerchantFile($this->tempDirectory.'/nested'))->find())->toThrow(CredentialException::class);
});

it('returns no selection when no binding exists', function (): void {
    expect((new ProjectMerchantFile($this->tempDirectory))->find())->toBeNull();
});

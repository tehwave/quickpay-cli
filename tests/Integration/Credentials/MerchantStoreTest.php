<?php

use App\Credentials\EnvironmentVariables;
use App\Credentials\Exceptions\CredentialException;
use App\Credentials\HomeDirectory;
use App\Credentials\MerchantStore;

beforeEach(function (): void {
    $this->tempDirectory = sys_get_temp_dir().'/quickpay-merchants-'.bin2hex(random_bytes(8));
    mkdir($this->tempDirectory, 0700, true);
    $this->path = $this->tempDirectory.'/nested/config.json';
});

afterEach(function (): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->tempDirectory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->tempDirectory);
});

it('keeps the first default when adding another merchant', function (): void {
    $store = new MerchantStore($this->path);
    $store->add('shop-a', 'fake-secret-a');
    $store->add('shop-b', 'fake-secret-b');
    expect($store->read())->toBe([
        'default_merchant' => 'shop-a',
        'merchants' => ['shop-a' => ['api_key' => 'fake-secret-a'], 'shop-b' => ['api_key' => 'fake-secret-b']],
    ]);
});

it('does not silently choose a default after removing it', function (): void {
    $store = new MerchantStore($this->path);
    $store->add('shop-a', 'fake-secret-a');
    $store->add('shop-b', 'fake-secret-b');
    $store->remove('shop-a');
    $store->add('shop-c', 'fake-secret-c');
    expect($store->read()['default_merchant'])->toBeNull()
        ->and(array_keys($store->read()['merchants']))->toBe(['shop-b', 'shop-c']);
});

it('retains existing credentials on duplicate or invalid additions', function (string $name, string $key): void {
    $store = new MerchantStore($this->path);
    $store->add('shop-a', 'fake-original');
    $before = file_get_contents($this->path);
    expect(fn () => $store->add($name, $key))->toThrow(CredentialException::class);
    expect(file_get_contents($this->path))->toBe($before);
})->with(['duplicate' => ['shop-a', 'fake-new'], 'empty key' => ['shop-b', ' '], 'secret label' => ['secret-name', 'secret']]);

it('replaces only the explicitly named credential', function (): void {
    $store = new MerchantStore($this->path);
    $store->add('shop-a', 'fake-secret-a');
    $store->add('shop-b', 'fake-secret-b');
    $store->add('shop-b', ' fake-replacement ', true);
    expect($store->read())->toBe([
        'default_merchant' => 'shop-a',
        'merchants' => ['shop-a' => ['api_key' => 'fake-secret-a'], 'shop-b' => ['api_key' => 'fake-replacement']],
    ]);
});

it('rejects labels colliding with any saved credential without changing storage', function (bool $incomingKey): void {
    $store = new MerchantStore($this->path);
    $store->add('shop-a', 'fake-secret-a');
    $before = file_get_contents($this->path);
    expect(fn () => $store->add($incomingKey ? 'shop-b' : 'fake-secret-a', $incomingKey ? 'shop-a' : 'fake-secret-b'))
        ->toThrow(CredentialException::class);
    expect(file_get_contents($this->path))->toBe($before);
})->with(['new label contains old key' => false, 'new key matches old label' => true]);

it('writes object-shaped empty storage and restrictive modes', function (): void {
    $store = new MerchantStore($this->path);
    $store->add('shop-a', 'fake-secret-a');
    $store->remove('shop-a');
    clearstatcache();
    $decoded = json_decode(file_get_contents($this->path), flags: JSON_THROW_ON_ERROR);
    expect($decoded->merchants)->toBeInstanceOf(stdClass::class)
        ->and($decoded->default_merchant)->toBeNull()
        ->and(fileperms(dirname($this->path)) & 0777)->toBe(0700)
        ->and(fileperms($this->path) & 0777)->toBe(0600)
        ->and(fileperms($this->path.'.lock') & 0777)->toBe(0600)
        ->and(glob(dirname($this->path).'/.config.json.*'))->toBe([]);
});

it('rejects invalid storage without treating it as empty', function (string $contents): void {
    mkdir(dirname($this->path), 0700, true);
    file_put_contents($this->path, $contents);
    $store = new MerchantStore($this->path);
    foreach ([fn () => $store->read(), fn () => $store->add('shop-a', 'fake-new'), fn () => $store->select('shop-a'), fn () => $store->remove('shop-a')] as $action) {
        expect($action)->toThrow(CredentialException::class);
        expect(file_get_contents($this->path))->toBe($contents);
    }
})->with([
    'broken' => '{broken', 'root list' => '[]', 'missing' => '{}',
    'merchant list' => '{"default_merchant":null,"merchants":[]}',
    'numeric key' => '{"default_merchant":null,"merchants":{"shop-a":{"api_key":123}}}',
    'empty key' => '{"default_merchant":null,"merchants":{"shop-a":{"api_key":" "}}}',
    'invalid name' => '{"default_merchant":null,"merchants":{"../a":{"api_key":"fake-key"}}}',
    'dangling default' => '{"default_merchant":"shop-a","merchants":{}}',
    'old shape' => '{"api_key":"fake-legacy"}',
    'secret label' => '{"default_merchant":null,"merchants":{"secret-name":{"api_key":"secret"}}}',
    'duplicate merchant' => '{"default_merchant":"shop-a","merchants":{"shop-a":{"api_key":"fake-first"},"shop-a":{"api_key":"fake-last"}}}',
    'escaped duplicate merchant' => '{"default_merchant":"shop-a","merchants":{"shop-a":{"api_key":"fake-first"},"sh\\u006fp-a":{"api_key":"fake-last"}}}',
    'duplicate key' => '{"default_merchant":"shop-a","merchants":{"shop-a":{"api_key":"fake-first","api_key":"fake-last"}}}',
    'duplicate default' => '{"default_merchant":"shop-a","default_merchant":null,"merchants":{"shop-a":{"api_key":"fake-first"}}}',
]);

it('does not mistake quoted key-like credential content for JSON properties', function (): void {
    $key = 'fake-{"same":1,"same":2}-[]';
    $store = new MerchantStore($this->path);
    $store->add('shop-a', $key);
    expect($store->read()['merchants']['shop-a']['api_key'])->toBe($key);
});

it('does not read old namespaced storage', function (): void {
    $old = $this->tempDirectory.'/.config/quickpay/config.json';
    mkdir(dirname($old), 0700, true);
    file_put_contents($old, '{"api_key":"fake-legacy"}');
    $store = MerchantStore::inHome(new HomeDirectory(new EnvironmentVariables, $this->tempDirectory));
    expect($store->path())->toBe($this->tempDirectory.'/.config/quickpay-cli/config.json')
        ->and($store->read())->toBe(['default_merchant' => null, 'merchants' => []])
        ->and(file_get_contents($old))->toBe('{"api_key":"fake-legacy"}');
});

it('rejects unknown select and remove targets', function (): void {
    $store = new MerchantStore($this->path);
    $store->add('shop-a', 'fake-secret-a');
    $store->add('shop-b', 'fake-secret-b');
    $before = file_get_contents($this->path);
    expect(fn () => $store->select('shop-c'))->toThrow(CredentialException::class);
    expect(fn () => $store->remove('shop-c'))->toThrow(CredentialException::class);
    expect(file_get_contents($this->path))->toBe($before);
    $store->select('shop-b');
    $store->remove('shop-a');
    expect($store->read()['default_merchant'])->toBe('shop-b');
});

it('preserves simultaneous writer updates', function (): void {
    $store = new MerchantStore($this->path);
    $store->add('initial', 'fake-initial');
    $script = $this->tempDirectory.'/writer.php';
    file_put_contents($script, <<<'PHP'
    <?php
    require $argv[1];
    $store = new App\Credentials\MerchantStore($argv[2]);
    echo "ready\n";
    fgets(STDIN);
    for ($i = 0; $i < 15; $i++) {
        $name = $argv[3].'-'.$i;
        $store->add($name, 'fake-key-'.$name);
    }
    PHP);
    $processes = [];
    try {
        foreach (['left', 'right'] as $name) {
            $process = proc_open([PHP_BINARY, $script, base_path('vendor/autoload.php'), $this->path, $name], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $processes[] = [$process, $pipes];
            stream_set_timeout($pipes[1], 5);
            expect(fgets($pipes[1]))->toBe("ready\n");
        }
        foreach ($processes as [$process, $pipes]) {
            fwrite($pipes[0], "go\n");
            fclose($pipes[0]);
        }
        foreach ($processes as [$process, $pipes]) {
            stream_get_contents($pipes[1]);
            expect(stream_get_meta_data($pipes[1])['timed_out'])->toBeFalse();
            expect(proc_close($process))->toBe(0);
        }
        $saved = $store->read()['merchants'];
        expect($saved)->toHaveCount(31);
        foreach (['left', 'right'] as $name) {
            for ($i = 0; $i < 15; $i++) {
                expect($saved[$name.'-'.$i]['api_key'])->toBe('fake-key-'.$name.'-'.$i);
            }
        }
    } finally {
        foreach ($processes as [$process, $pipes]) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
    }
});

it('waits on a stable lock during replacement', function (): void {
    $store = new MerchantStore($this->path);
    $store->add('shop-a', 'fake-secret-a');
    $lock = fopen($this->path.'.lock', 'c');
    flock($lock, LOCK_EX);
    $script = $this->tempDirectory.'/locked-writer.php';
    file_put_contents($script, <<<'PHP'
    <?php
    require $argv[1];
    echo "ready\n";
    (new App\Credentials\MerchantStore($argv[2]))->add('shop-b', 'fake-secret-b');
    echo "done\n";
    PHP);
    $process = proc_open([PHP_BINARY, $script, base_path('vendor/autoload.php'), $this->path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    try {
        stream_set_timeout($pipes[1], 5);
        expect(fgets($pipes[1]))->toBe("ready\n");
        $read = [$pipes[1]];
        $write = $except = [];
        expect(stream_select($read, $write, $except, 0, 50000))->toBe(0);
        file_put_contents($this->path.'.replacement', '{"default_merchant":"shop-c","merchants":{"shop-c":{"api_key":"fake-secret-c"}}}');
        rename($this->path.'.replacement', $this->path);
        flock($lock, LOCK_UN);
        expect(fgets($pipes[1]))->toBe("done\n")
            ->and(proc_close($process))->toBe(0)
            ->and(array_keys($store->read()['merchants']))->toBe(['shop-c', 'shop-b']);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }
});

it('keeps persistence errors free of credentials', function (): void {
    mkdir($this->path, 0700, true);
    try {
        (new MerchantStore($this->path))->add('shop-a', 'fake-sensitive');
        $this->fail('Expected persistence to fail');
    } catch (CredentialException $exception) {
        expect($exception->getMessage())->not->toContain('fake-sensitive');
    }
});

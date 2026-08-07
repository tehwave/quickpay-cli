<?php

namespace App\Credentials;

use App\Credentials\Exceptions\CredentialException;
use JsonException;

final readonly class CredentialFile
{
    public function __construct(private string $path) {}

    public static function inHome(HomeDirectory $home): self
    {
        return new self(rtrim($home->path(), DIRECTORY_SEPARATOR).'/.config/quickpay/config.json');
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function read(): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        $contents = @file_get_contents($this->path);

        if (! is_string($contents)) {
            throw new CredentialException('Quickpay credential file is invalid or cannot be read.');
        }

        try {
            $config = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CredentialException('Quickpay credential file is invalid JSON.');
        }

        if (! is_array($config)
            || ! isset($config['api_key'])
            || ! is_string($config['api_key'])
            || trim($config['api_key']) === '') {
            throw new CredentialException('Quickpay credential file is invalid: expected a non-empty api_key string.');
        }

        return $config['api_key'];
    }

    public function save(string $apiKey): void
    {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            throw new CredentialException('Unable to store Quickpay credentials: the API key is empty.');
        }

        $directory = dirname($this->path);

        if ((! is_dir($directory) && ! @mkdir($directory, 0700, true))
            || ! @chmod($directory, 0700)) {
            throw new CredentialException('Unable to store Quickpay credentials: the config directory is not writable.');
        }

        if (is_dir($this->path)) {
            throw new CredentialException('Unable to store Quickpay credentials atomically.');
        }

        $temporaryPath = @tempnam($directory, '.'.basename($this->path).'.');

        if (! is_string($temporaryPath)) {
            throw new CredentialException('Unable to store Quickpay credentials: a temporary file could not be created.');
        }

        try {
            $json = json_encode(['api_key' => $apiKey], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

            if (@file_put_contents($temporaryPath, $json, LOCK_EX) === false
                || ! @chmod($temporaryPath, 0600)
                || ! @rename($temporaryPath, $this->path)) {
                throw new CredentialException('Unable to store Quickpay credentials atomically.');
            }

            $temporaryPath = '';
        } catch (JsonException) {
            throw new CredentialException('Unable to store Quickpay credentials.');
        } finally {
            if ($temporaryPath !== '' && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    public function forget(): bool
    {
        if (! $this->exists()) {
            return false;
        }

        if (! @unlink($this->path)) {
            throw new CredentialException('Unable to remove the stored Quickpay credential.');
        }

        return true;
    }
}

<?php

namespace App\Credentials;

use App\Credentials\Exceptions\CredentialStoreException;
use JsonException;

/**
 * Resolves and persists the single Quickpay API credential.
 *
 * Environment credentials intentionally take precedence so CI and secret
 * managers never need to write a long-lived key to a developer's config file.
 */
final readonly class CredentialStore
{
    private string $path;

    public function __construct(?string $configPath = null, ?string $home = null)
    {
        if ($configPath !== null) {
            $this->path = $configPath;

            return;
        }

        $resolvedHome = $home ?? $this->environmentHome();
        $this->path = rtrim($resolvedHome, DIRECTORY_SEPARATOR).'/.config/quickpay/config.json';
    }

    public function configPath(): string
    {
        return $this->path;
    }

    public function apiKey(): ?string
    {
        $environmentKey = getenv('QUICKPAY_API_KEY');

        if (is_string($environmentKey) && trim($environmentKey) !== '') {
            return $environmentKey;
        }

        if (! is_file($this->path)) {
            return null;
        }

        $contents = @file_get_contents($this->path);

        if (! is_string($contents)) {
            throw new CredentialStoreException('Quickpay credential file is invalid or cannot be read.');
        }

        try {
            $config = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CredentialStoreException('Quickpay credential file is invalid JSON.');
        }

        if (! is_array($config)
            || ! isset($config['api_key'])
            || ! is_string($config['api_key'])
            || trim($config['api_key']) === '') {
            throw new CredentialStoreException('Quickpay credential file is invalid: expected a non-empty api_key string.');
        }

        return $config['api_key'];
    }

    public function source(): string
    {
        $environmentKey = getenv('QUICKPAY_API_KEY');

        if (is_string($environmentKey) && trim($environmentKey) !== '') {
            return 'environment';
        }

        return $this->apiKey() === null ? 'none' : 'config file';
    }

    public function hasStored(): bool
    {
        return is_file($this->path);
    }

    public function save(string $apiKey): void
    {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            throw new CredentialStoreException('Unable to store Quickpay credentials: the API key is empty.');
        }

        $directory = dirname($this->path);

        if ((! is_dir($directory) && ! @mkdir($directory, 0700, true))
            || ! @chmod($directory, 0700)) {
            throw new CredentialStoreException('Unable to store Quickpay credentials: the config directory is not writable.');
        }

        $temporaryPath = @tempnam($directory, '.'.basename($this->path).'.');

        if (! is_string($temporaryPath)) {
            throw new CredentialStoreException('Unable to store Quickpay credentials: a temporary file could not be created.');
        }

        try {
            $json = json_encode(['api_key' => $apiKey], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

            // Write and restrict a sibling file before renaming it. Readers
            // therefore see either the old complete config or the new one,
            // never partially written JSON or a briefly world-readable key.
            if (@file_put_contents($temporaryPath, $json, LOCK_EX) === false
                || ! @chmod($temporaryPath, 0600)
                || ! @rename($temporaryPath, $this->path)) {
                throw new CredentialStoreException('Unable to store Quickpay credentials atomically.');
            }

            $temporaryPath = '';
        } catch (JsonException) {
            throw new CredentialStoreException('Unable to store Quickpay credentials.');
        } finally {
            if ($temporaryPath !== '' && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    public function forgetStored(): bool
    {
        if (! is_file($this->path)) {
            return false;
        }

        if (! @unlink($this->path)) {
            throw new CredentialStoreException('Unable to remove the stored Quickpay credential.');
        }

        return true;
    }

    private function environmentHome(): string
    {
        foreach (['HOME', 'USERPROFILE'] as $variable) {
            $value = getenv($variable);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        throw new CredentialStoreException('Unable to locate the home directory for Quickpay credentials.');
    }
}

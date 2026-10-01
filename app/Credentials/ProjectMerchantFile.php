<?php

namespace App\Credentials;

use App\Credentials\Exceptions\CredentialException;
use JsonException;
use stdClass;

final readonly class ProjectMerchantFile
{
    public function __construct(private ?string $directory = null) {}

    /** @return array{merchant: string, path: string}|null */
    public function find(): ?array
    {
        $directory = $this->directory();
        while (true) {
            $path = $directory.'/.quickpay-cli.json';
            if (file_exists($path) || is_link($path)) {
                return ['merchant' => $this->read($path), 'path' => $path];
            }
            $parent = dirname($directory);
            if ($parent === $directory) {
                return null;
            }
            $directory = $parent;
        }
    }

    public function save(string $merchant): void
    {
        MerchantName::validate($merchant);
        $directory = $this->directory();
        $path = $directory.'/.quickpay-cli.json';
        if (file_exists($path) || is_link($path)) {
            $this->read($path);
        }
        $temporary = @tempnam($directory, '..quickpay-cli.json.');
        if ($temporary === false) {
            throw new CredentialException('Unable to create a temporary project merchant binding.');
        }
        try {
            $json = json_encode(['merchant' => $merchant], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
            if (@file_put_contents($temporary, $json) === false || ! @rename($temporary, $path)) {
                throw new CredentialException('Unable to save the project merchant binding atomically.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function directory(): string
    {
        $directory = $this->directory ?? getcwd();
        $resolved = is_string($directory) ? realpath($directory) : false;
        if ($resolved === false || ! is_dir($resolved)) {
            throw new CredentialException('Unable to locate the current project directory.');
        }

        return $resolved;
    }

    private function read(string $path): string
    {
        $contents = is_file($path) ? @file_get_contents($path) : false;
        if ($contents === false) {
            throw new CredentialException('Project merchant binding cannot be read.');
        }
        try {
            $config = MerchantConfigurationJson::decode($contents);
        } catch (JsonException) {
            throw new CredentialException('Project merchant binding is invalid JSON.');
        }
        if (! $config instanceof stdClass || count(get_object_vars($config)) !== 1 || ! isset($config->merchant) || ! is_string($config->merchant)) {
            throw new CredentialException('Project merchant binding must contain only a merchant name.');
        }

        return MerchantName::validate($config->merchant);
    }
}

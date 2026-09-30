<?php

namespace App\Credentials;

use App\Credentials\Exceptions\CredentialException;
use App\Credentials\Redaction\CredentialRedactor;
use Closure;
use JsonException;
use stdClass;

/** @phpstan-type MerchantConfig array{default_merchant: ?string, merchants: array<string, array{api_key: string}>} */
final readonly class MerchantStore
{
    public function __construct(private string $path) {}

    public static function inHome(HomeDirectory $home): self
    {
        return new self(rtrim($home->path(), DIRECTORY_SEPARATOR).'/.config/quickpay-cli/config.json');
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return MerchantConfig */
    public function read(): array
    {
        if (! file_exists($this->path) && ! is_link($this->path)) {
            return ['default_merchant' => null, 'merchants' => []];
        }

        $contents = is_file($this->path) ? @file_get_contents($this->path) : false;
        if ($contents === false) {
            throw new CredentialException('Quickpay CLI merchant configuration cannot be read.');
        }

        try {
            $config = json_decode($contents, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CredentialException('Quickpay CLI merchant configuration is invalid JSON.');
        }

        if ($config instanceof stdClass && property_exists($config, 'api_key')) {
            throw new CredentialException('Unsupported single-key configuration. Add merchants again with quickpay merchants:add <name> after removing this unsupported file.');
        }

        if (! $config instanceof stdClass
            || count(get_object_vars($config)) !== 2
            || ! property_exists($config, 'default_merchant')
            || ! isset($config->merchants)
            || ! $config->merchants instanceof stdClass
            || ($config->default_merchant !== null && ! is_string($config->default_merchant))) {
            throw new CredentialException('Quickpay CLI merchant configuration has an invalid structure.');
        }

        $merchants = [];
        foreach (get_object_vars($config->merchants) as $name => $merchant) {
            MerchantName::validate((string) $name);
            if (! $merchant instanceof stdClass
                || count(get_object_vars($merchant)) !== 1
                || ! isset($merchant->api_key)
                || ! is_string($merchant->api_key)
                || trim($merchant->api_key) === '') {
                throw new CredentialException('Quickpay CLI merchant configuration contains an invalid credential.');
            }
            $this->validateLabel((string) $name, $merchant->api_key);
            $merchants[$name] = ['api_key' => $merchant->api_key];
        }

        if ($config->default_merchant !== null && ! isset($merchants[$config->default_merchant])) {
            throw new CredentialException('Quickpay CLI default merchant is not saved.');
        }

        return ['default_merchant' => $config->default_merchant, 'merchants' => $merchants];
    }

    public function add(string $name, string $apiKey, bool $replace = false): void
    {
        MerchantName::validate($name);
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new CredentialException('API key cannot be empty.');
        }
        $this->validateLabel($name, $apiKey);

        $this->mutate(function (array $config) use ($name, $apiKey, $replace): array {
            if (isset($config['merchants'][$name]) && ! $replace) {
                throw new CredentialException('Merchant is already saved. Use --replace to replace its credential.');
            }
            if ($config['merchants'] === []) {
                $config['default_merchant'] = $name;
            }
            $config['merchants'][$name] = ['api_key' => $apiKey];

            return $config;
        });
    }

    public function select(string $name): void
    {
        MerchantName::validate($name);
        $this->mutate(function (array $config) use ($name): array {
            $this->requireMerchant($config, $name);
            $config['default_merchant'] = $name;

            return $config;
        });
    }

    public function remove(string $name): void
    {
        MerchantName::validate($name);
        $this->mutate(function (array $config) use ($name): array {
            $this->requireMerchant($config, $name);
            unset($config['merchants'][$name]);
            if ($config['default_merchant'] === $name) {
                $config['default_merchant'] = null;
            }

            return $config;
        });
    }

    private function validateLabel(string $name, string $apiKey): void
    {
        if (CredentialRedactor::containsSensitiveValue($name, $apiKey)) {
            throw new CredentialException('Merchant name must not contain the API key.');
        }
    }

    /** @param MerchantConfig $config */
    private function requireMerchant(array $config, string $name): void
    {
        if (! isset($config['merchants'][$name])) {
            throw new CredentialException('Merchant is not saved. Run quickpay merchants:list to see saved names.');
        }
    }

    /** @param Closure(MerchantConfig): MerchantConfig $change */
    private function mutate(Closure $change): void
    {
        $directory = dirname($this->path);
        if ((! is_dir($directory) && ! @mkdir($directory, 0700, true)) || ! @chmod($directory, 0700)) {
            throw new CredentialException('Unable to store merchant credentials: the config directory is not writable.');
        }

        // ponytail: one lock for the small credential registry; split storage if write contention matters.
        $lock = @fopen($this->path.'.lock', 'c');
        if ($lock === false) {
            throw new CredentialException('Unable to lock merchant credentials.');
        }

        $temporary = '';
        try {
            if (! @chmod($this->path.'.lock', 0600) || ! flock($lock, LOCK_EX)) {
                throw new CredentialException('Unable to lock merchant credentials.');
            }
            $config = $change($this->read());
            $json = json_encode([
                'default_merchant' => $config['default_merchant'],
                'merchants' => (object) $config['merchants'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
            $path = @tempnam($directory, '.'.basename($this->path).'.');
            if ($path === false) {
                throw new CredentialException('Unable to create a temporary merchant configuration.');
            }
            $temporary = $path;
            if (@file_put_contents($temporary, $json) === false || ! @chmod($temporary, 0600) || ! @rename($temporary, $this->path)) {
                throw new CredentialException('Unable to store merchant credentials atomically.');
            }
            $temporary = '';
        } catch (JsonException) {
            throw new CredentialException('Unable to encode merchant credentials.');
        } finally {
            if ($temporary !== '' && is_file($temporary)) {
                @unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

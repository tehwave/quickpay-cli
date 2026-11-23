<?php

namespace App\Credentials;

use App\Credentials\Exceptions\CredentialException;
use App\Credentials\Redaction\CredentialRedactor;
use Closure;
use JsonException;
use stdClass;

/**
 * @phpstan-type SavedMerchant array{api_key: string, read: bool, write: bool}
 * @phpstan-type MerchantConfig array{default_merchant: ?string, merchants: array<string, SavedMerchant>}
 */
final readonly class MerchantStore
{
    public function __construct(
        private string $path,
        private ?string $legacyPath = null,
    ) {}

    public static function inHome(HomeDirectory $home): self
    {
        $home = rtrim($home->path(), DIRECTORY_SEPARATOR);

        return new self($home.'/.config/quickpay-cli/config.json', $home.'/.config/quickpay/config.json');
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Returns the pre-merchant credential path when that file still exists.
     *
     * It is never read or migrated; callers only tell the user it is unused.
     */
    public function leftoverLegacyCredential(): ?string
    {
        return $this->legacyPath !== null && (file_exists($this->legacyPath) || is_link($this->legacyPath))
            ? $this->legacyPath
            : null;
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
            $config = MerchantConfigurationJson::decode($contents);
        } catch (JsonException) {
            throw new CredentialException('Quickpay CLI merchant configuration is invalid JSON.');
        }

        if ($config instanceof stdClass && property_exists($config, 'api_key')) {
            throw new CredentialException('Unsupported single-key configuration. Add merchants again with quickpay merchants:add <name> after removing this unsupported file.');
        }

        if (! $config instanceof stdClass
            || count(get_object_vars($config)) !== 2
            || ! property_exists($config, 'default_merchant')
            || ! ($config->merchants ?? null) instanceof stdClass
            || ($config->default_merchant !== null && ! is_string($config->default_merchant))) {
            throw new CredentialException('Quickpay CLI merchant configuration has an invalid structure.');
        }

        $merchants = [];
        foreach (get_object_vars($config->merchants) as $name => $merchant) {
            MerchantName::validate((string) $name);
            if (! $merchant instanceof stdClass
                || ! is_string($merchant->api_key ?? null)
                || trim($merchant->api_key) === '') {
                throw new CredentialException('Quickpay CLI merchant configuration contains an invalid credential.');
            }
            if (count(get_object_vars($merchant)) !== 3
                || ! is_bool($merchant->read ?? null)
                || ! is_bool($merchant->write ?? null)) {
                throw new CredentialException('Quickpay CLI merchant configuration needs exactly an API key and true or false read and write access flags for each merchant.');
            }
            $merchants[$name] = ['api_key' => $merchant->api_key, 'read' => $merchant->read, 'write' => $merchant->write];
        }
        $this->validateLabels($merchants);

        if ($config->default_merchant !== null && ! isset($merchants[$config->default_merchant])) {
            throw new CredentialException('Quickpay CLI default merchant is not saved.');
        }

        return ['default_merchant' => $config->default_merchant, 'merchants' => $merchants];
    }

    /**
     * Saves a credential and returns the access it was saved with. A null flag
     * keeps the replaced merchant's flag, or allows that kind of request for a
     * new merchant.
     */
    public function add(string $name, string $apiKey, bool $replace = false, ?bool $read = null, ?bool $write = null): MerchantAccess
    {
        MerchantName::validate($name);
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new CredentialException('API key cannot be empty.');
        }
        $access = null;
        $this->mutate(function (array $config) use ($name, $apiKey, $replace, $read, $write, &$access): array {
            if (isset($config['merchants'][$name]) && ! $replace) {
                throw new CredentialException('Merchant is already saved. Use --replace to replace its credential.');
            }
            if (! isset($config['merchants'][$name]) && $replace) {
                throw new CredentialException('Merchant is not saved, so there is no credential to replace. Run quickpay merchants:list to see saved names.');
            }
            if ($config['merchants'] === []) {
                $config['default_merchant'] = $name;
            }
            $existing = $config['merchants'][$name] ?? null;
            $access = new MerchantAccess($read ?? $existing['read'] ?? true, $write ?? $existing['write'] ?? true);
            $config['merchants'][$name] = ['api_key' => $apiKey, 'read' => $access->read, 'write' => $access->write];
            $this->validateLabels($config['merchants']);

            return $config;
        });

        /** @var MerchantAccess $access */
        return $access;
    }

    public function select(string $name): void
    {
        $this->mutate(function (array $config) use ($name): array {
            $this->requireMerchant($config, $name);
            $config['default_merchant'] = $name;

            return $config;
        });
    }

    /** Changes the given flags under the store lock; a null flag is kept. */
    public function changeAccess(string $name, ?bool $read = null, ?bool $write = null): MerchantAccess
    {
        MerchantName::validate($name);
        $access = null;
        $this->mutate(function (array $config) use ($name, $read, $write, &$access): array {
            $this->requireMerchant($config, $name);
            $saved = $config['merchants'][$name];
            $access = new MerchantAccess($read ?? $saved['read'], $write ?? $saved['write']);
            $config['merchants'][$name] = [...$saved, 'read' => $access->read, 'write' => $access->write];

            return $config;
        });

        /** @var MerchantAccess $access */
        return $access;
    }

    public function remove(string $name): void
    {
        $this->mutate(function (array $config) use ($name): array {
            $this->requireMerchant($config, $name);
            unset($config['merchants'][$name]);
            if ($config['default_merchant'] === $name) {
                $config['default_merchant'] = null;
            }

            return $config;
        });
    }

    /** @param array<string, SavedMerchant> $merchants */
    private function validateLabels(array $merchants): void
    {
        // ponytail: quadratic check for the small registry; cache a multi-key matcher if large registries become common.
        foreach (array_keys($merchants) as $name) {
            foreach ($merchants as $credential) {
                if (CredentialRedactor::containsSensitiveValue($name, $credential['api_key'])) {
                    throw new CredentialException('Merchant name must not contain the API key.');
                }
            }
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

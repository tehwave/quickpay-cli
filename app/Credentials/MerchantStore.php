<?php

namespace App\Credentials;

use App\Credentials\Exceptions\CredentialException;
use Closure;
use JsonException;

/**
 * @phpstan-import-type MerchantConfig from MerchantConfigurationParser
 */
final readonly class MerchantStore
{
    /** @var Closure(): string */
    private Closure $path;

    /** @var Closure(): ?string */
    private Closure $legacyPath;

    /**
     * @param  string|Closure(): string  $path
     * @param  string|Closure(): ?string|null  $legacyPath
     */
    public function __construct(string|Closure $path, string|Closure|null $legacyPath = null)
    {
        $this->path = $path instanceof Closure ? $path : fn (): string => $path;
        $this->legacyPath = $legacyPath instanceof Closure ? $legacyPath : fn (): ?string => $legacyPath;
    }

    /**
     * The home folder is looked up only when the configuration is used. A
     * missing HOME then fails as an ordinary command error, and commands that
     * never touch saved merchants, such as QUICKPAY_API_KEY runs, still work.
     */
    public static function inHome(HomeDirectory $home): self
    {
        $base = fn (): string => rtrim($home->path(), DIRECTORY_SEPARATOR);

        return new self(
            fn (): string => $base().'/.config/quickpay-cli/config.json',
            fn (): string => $base().'/.config/quickpay/config.json',
        );
    }

    public function path(): string
    {
        return ($this->path)();
    }

    /**
     * Returns the pre-merchant credential path when that file still exists.
     *
     * It is never read or migrated; callers only tell the user it is unused.
     */
    public function leftoverLegacyCredential(): ?string
    {
        $legacyPath = ($this->legacyPath)();

        return $legacyPath !== null && (file_exists($legacyPath) || is_link($legacyPath))
            ? $legacyPath
            : null;
    }

    /** @return MerchantConfig */
    public function read(): array
    {
        $path = $this->path();
        if (! file_exists($path) && ! is_link($path)) {
            return ['default_merchant' => null, 'merchants' => []];
        }

        $contents = is_file($path) ? @file_get_contents($path) : false;
        if ($contents === false) {
            throw new CredentialException('Quickpay CLI merchant configuration cannot be read.');
        }

        return MerchantConfigurationParser::parse($contents, $path);
    }

    /**
     * Saves a credential and returns the access it was saved with. A
     * replacement keeps a read-only merchant read-only: only changeAccess can
     * allow writes again.
     */
    public function add(string $name, string $apiKey, bool $replace = false, bool $readOnly = false): MerchantAccess
    {
        MerchantName::validate($name);
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new CredentialException('API key cannot be empty.');
        }
        $config = $this->mutate(function (array $config) use ($name, $apiKey, $replace, $readOnly): array {
            self::assertCanAdd($config, $name, $replace);
            if ($config['merchants'] === []) {
                $config['default_merchant'] = $name;
            }
            $existing = $config['merchants'][$name] ?? null;
            $config['merchants'][$name] = ['api_key' => $apiKey, 'read_only' => $readOnly || ($existing['read_only'] ?? false)];
            MerchantConfigurationParser::validateLabels($config['merchants']);

            return $config;
        });

        return new MerchantAccess($config['merchants'][$name]['read_only']);
    }

    /**
     * Fails when $name cannot be added to $config, or replaced in it.
     *
     * merchants:add calls this before asking for the key, and add() calls it
     * again under the lock in case the file changed in between.
     *
     * @param  MerchantConfig  $config
     */
    public static function assertCanAdd(array $config, string $name, bool $replace): void
    {
        $saved = isset($config['merchants'][$name]);
        if ($saved && ! $replace) {
            throw new CredentialException('Merchant is already saved. Use --replace to replace its credential.');
        }
        if (! $saved && $replace) {
            throw new CredentialException('Merchant is not saved, so there is no credential to replace. Run quickpay merchants:list to see saved names.');
        }
        $variant = MerchantName::caseVariant($name, array_keys($config['merchants']));
        if (! $saved && $variant !== null) {
            throw new CredentialException("Merchant {$variant} is already saved with different capitalization. Use that name, or choose a clearly different one.");
        }
    }

    public function select(string $name): void
    {
        $this->mutate(function (array $config) use ($name): array {
            $this->requireMerchant($config, $name);
            $config['default_merchant'] = $name;

            return $config;
        });
    }

    /** Sets the read_only flag under the store lock. */
    public function changeAccess(string $name, bool $readOnly): MerchantAccess
    {
        MerchantName::validate($name);
        $this->mutate(function (array $config) use ($name, $readOnly): array {
            $this->requireMerchant($config, $name);
            $config['merchants'][$name] = [...$config['merchants'][$name], 'read_only' => $readOnly];

            return $config;
        });

        return new MerchantAccess($readOnly);
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

    /** @param MerchantConfig $config */
    private function requireMerchant(array $config, string $name): void
    {
        if (! isset($config['merchants'][$name])) {
            throw new CredentialException('Merchant is not saved. Run quickpay merchants:list to see saved names.');
        }
    }

    /**
     * Applies $change to the configuration under the lock and saves it.
     *
     * @param  Closure(MerchantConfig): MerchantConfig  $change
     * @return MerchantConfig the configuration as saved
     */
    private function mutate(Closure $change): array
    {
        $file = $this->path();
        $directory = dirname($file);
        if ((! is_dir($directory) && ! @mkdir($directory, 0700, true)) || ! @chmod($directory, 0700)) {
            throw new CredentialException('Unable to store merchant credentials: the config directory is not writable.');
        }

        // ponytail: one lock for the small credential registry; split storage if write contention matters.
        $lock = @fopen($file.'.lock', 'c');
        if ($lock === false) {
            throw new CredentialException('Unable to lock merchant credentials.');
        }

        $temporary = '';
        try {
            if (! @chmod($file.'.lock', 0600) || ! flock($lock, LOCK_EX)) {
                throw new CredentialException('Unable to lock merchant credentials.');
            }
            $config = $change($this->read());
            $json = json_encode([
                'default_merchant' => $config['default_merchant'],
                'merchants' => (object) $config['merchants'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
            $path = @tempnam($directory, '.'.basename($file).'.');
            if ($path === false) {
                throw new CredentialException('Unable to create a temporary merchant configuration.');
            }
            $temporary = $path;
            if (@file_put_contents($temporary, $json) === false || ! @chmod($temporary, 0600) || ! @rename($temporary, $file)) {
                throw new CredentialException('Unable to store merchant credentials atomically.');
            }
            $temporary = '';

            return $config;
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

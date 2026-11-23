<?php

namespace App\Credentials;

use App\Console\Output\ResponseBodySanitizer;
use App\Credentials\Exceptions\CredentialException;
use App\Credentials\Redaction\CredentialRedactor;
use JsonException;
use stdClass;

/**
 * Turns the saved merchant configuration into a validated config.
 *
 * It only decides whether stored content is well formed. MerchantStore owns
 * where the file lives and how it is locked and written.
 *
 * @phpstan-type SavedMerchant array{api_key: string, read_only: bool}
 * @phpstan-type MerchantConfig array{default_merchant: ?string, merchants: array<string, SavedMerchant>}
 */
final class MerchantConfigurationParser
{
    /** @return MerchantConfig */
    public static function parse(string $contents, string $path): array
    {
        $location = ResponseBodySanitizer::terminalLine($path, '');

        try {
            $config = MerchantConfigurationJson::decode($contents);
        } catch (JsonException) {
            throw new CredentialException('Quickpay CLI merchant configuration is invalid JSON or repeats a property.');
        }

        if ($config instanceof stdClass && property_exists($config, 'api_key')) {
            throw new CredentialException("Quickpay CLI merchant configuration {$location} uses the unsupported single-key format. Remove that file, then add merchants again with quickpay merchants:add <name>.");
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
            $merchants[$name] = self::merchant((string) $name, $merchant, $location);
        }
        self::validateLabels($merchants);

        if ($config->default_merchant !== null && ! isset($merchants[$config->default_merchant])) {
            throw new CredentialException('Quickpay CLI default merchant is not saved.');
        }

        return ['default_merchant' => $config->default_merchant, 'merchants' => $merchants];
    }

    /**
     * Fails when a merchant name contains any saved API key, so a name can
     * never be used to leak a key into output.
     *
     * @param  array<string, SavedMerchant>  $merchants
     */
    public static function validateLabels(array $merchants): void
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

    /** @return SavedMerchant */
    private static function merchant(string $name, mixed $merchant, string $location): array
    {
        try {
            MerchantName::validate($name);
        } catch (CredentialException $exception) {
            throw new CredentialException("Quickpay CLI merchant configuration {$location} contains an invalid merchant name. {$exception->getMessage()}");
        }

        if (! $merchant instanceof stdClass
            || ! is_string($merchant->api_key ?? null)
            || trim($merchant->api_key) === '') {
            throw new CredentialException('Quickpay CLI merchant configuration contains an invalid credential.');
        }

        if (count(get_object_vars($merchant)) !== 2 || ! is_bool($merchant->read_only ?? null)) {
            throw new CredentialException('Quickpay CLI merchant configuration needs exactly an API key and a true or false read_only flag for each merchant.');
        }

        return ['api_key' => $merchant->api_key, 'read_only' => $merchant->read_only];
    }
}

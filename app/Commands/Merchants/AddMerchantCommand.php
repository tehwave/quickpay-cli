<?php

namespace App\Commands\Merchants;

use App\Console\BaseCommand;
use App\Console\Output\ResponseBodySanitizer;
use App\Console\Terminal\StdinTerminalDetector;
use App\Credentials\ApiKey;
use App\Credentials\EnvironmentVariables;
use App\Credentials\Exceptions\CredentialException;
use App\Credentials\MerchantAccess;
use App\Credentials\MerchantName;
use App\Credentials\MerchantStore;
use App\Quickpay\Exceptions\QuickpayRequestException;
use App\Quickpay\MerchantAuthenticationCheck;
use UnexpectedValueException;

class AddMerchantCommand extends BaseCommand
{
    protected $signature = 'merchants:add
        {name : Local merchant name, for example "My Website"}
        {--replace : Replace this saved merchant credential after validation}
        {--read-only : Block write requests (POST, PUT, PATCH, DELETE). --replace keeps a read-only merchant read-only}';

    protected $description = 'Validate and save API access to an existing Quickpay merchant';

    public function handle(MerchantStore $store, MerchantAuthenticationCheck $check, StdinTerminalDetector $terminal, EnvironmentVariables $environment): int
    {
        $credential = null;

        try {
            $name = MerchantName::validate((string) $this->argument('name'));
            $config = $store->read();

            $saved = isset($config['merchants'][$name]);
            if ($saved && ! $this->option('replace')) {
                return $this->failure('Merchant is already saved. Use --replace to replace its credential.');
            }

            if (! $saved && $this->option('replace')) {
                return $this->failure('Merchant is not saved, so there is no credential to replace. Run quickpay merchants:list to see saved names.');
            }

            $variant = MerchantName::caseVariant($name, array_keys($config['merchants']));
            if (! $saved && $variant !== null) {
                return $this->failure("Merchant {$variant} is already saved with different capitalization. Use that name, or choose a clearly different one.");
            }

            if (! $this->input->isInteractive() || ! $terminal->isTty()) {
                return $this->failure('Adding a merchant requires an interactive terminal. Use QUICKPAY_API_KEY for automation.');
            }

            $answer = $this->secret('API key');
            $apiKey = is_string($answer) ? trim($answer) : '';

            if ($apiKey === '') {
                return $this->failure('API key cannot be empty.');
            }

            $credential = new ApiKey($apiKey, 'provided', MerchantAccess::full());
            if ($credential->appearsIn($name)) {
                return $this->failure('Merchant name must not contain the API key.');
            }

            $check->check($credential);
            $access = $store->add($name, $apiKey, (bool) $this->option('replace'), (bool) $this->option('read-only'));
            $this->info('Merchant saved: '.$name);
            if ($access->readOnly) {
                $this->line('Access: '.$access->label());
            }

            if ($environment->get('QUICKPAY_API_KEY') !== null) {
                $this->comment('QUICKPAY_API_KEY is set, so commands use it instead of your default merchant, and --merchant or a project binding fails. Unset it to use saved merchants.');
            }

            $legacy = $store->leftoverLegacyCredential();
            if ($legacy !== null) {
                $this->warn(ResponseBodySanitizer::terminalLine(
                    "Quickpay CLI no longer uses {$legacy} from earlier versions. Delete it to remove the API key it may still contain.",
                    $apiKey,
                ));
            }

            return self::SUCCESS;
        } catch (CredentialException|QuickpayRequestException|UnexpectedValueException $exception) {
            return $this->failure($credential?->redact($exception->getMessage()) ?? $exception->getMessage());
        }
    }
}

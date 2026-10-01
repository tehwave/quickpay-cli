<?php

namespace App\Commands\Merchants;

use App\Console\BaseCommand;
use App\Console\Terminal\StdinTerminalDetector;
use App\Credentials\ApiKey;
use App\Credentials\Exceptions\CredentialException;
use App\Credentials\MerchantName;
use App\Credentials\MerchantStore;
use App\Quickpay\Exceptions\QuickpayRequestException;
use App\Quickpay\MerchantAuthenticationCheck;
use UnexpectedValueException;

class AddMerchantCommand extends BaseCommand
{
    protected $signature = 'merchants:add
        {name : Local merchant name, for example shop-a}
        {--replace : Replace this saved merchant credential after validation}';

    protected $description = 'Validate and save API access to an existing Quickpay merchant';

    public function handle(MerchantStore $store, MerchantAuthenticationCheck $check, StdinTerminalDetector $terminal): int
    {
        $credential = null;
        try {
            $name = MerchantName::validate((string) $this->argument('name'));
            $config = $store->read();
            if (isset($config['merchants'][$name]) && ! $this->option('replace')) {
                return $this->failure('Merchant is already saved. Use --replace to replace its credential.');
            }
            if (! $this->input->isInteractive() || ! $terminal->isTty()) {
                return $this->failure('Adding a merchant requires an interactive terminal. Use QUICKPAY_API_KEY for automation.');
            }
            $answer = $this->secret('API key');
            $apiKey = is_string($answer) ? trim($answer) : '';
            if ($apiKey === '') {
                return $this->failure('API key cannot be empty.');
            }
            $credential = new ApiKey($apiKey, 'provided');
            if ($credential->appearsIn($name)) {
                return $this->failure('Merchant name must not contain the API key.');
            }
            $check->check($credential);
            $store->add($name, $apiKey, (bool) $this->option('replace'));
            $this->info('Merchant saved: '.$name);

            return self::SUCCESS;
        } catch (CredentialException|QuickpayRequestException|UnexpectedValueException $exception) {
            return $this->failure($credential?->redact($exception->getMessage()) ?? $exception->getMessage());
        }
    }
}

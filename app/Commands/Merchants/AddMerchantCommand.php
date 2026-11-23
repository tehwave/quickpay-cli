<?php

namespace App\Commands\Merchants;

use App\Console\BaseCommand;
use App\Console\Output\ResponseBodySanitizer;
use App\Console\Terminal\StdinTerminalDetector;
use App\Credentials\ApiKey;
use App\Credentials\Exceptions\CredentialException;
use App\Credentials\MerchantAccess;
use App\Credentials\MerchantName;
use App\Credentials\MerchantStore;
use App\Quickpay\Exceptions\QuickpayRequestException;
use App\Quickpay\MerchantAuthenticationCheck;
use Symfony\Component\Console\Input\InputOption;
use UnexpectedValueException;

class AddMerchantCommand extends BaseCommand
{
    protected $signature = 'merchants:add
        {name : Local merchant name, for example "My Website"}
        {--replace : Replace this saved merchant credential after validation}';

    protected $description = 'Validate and save API access to an existing Quickpay merchant';

    public function __construct()
    {
        parent::__construct();
        $this->addOption('read', null, InputOption::VALUE_NEGATABLE, 'Allow read requests (GET). Allowed by default; --replace keeps the saved setting');
        $this->addOption('write', null, InputOption::VALUE_NEGATABLE, 'Allow write requests (POST, PUT, PATCH, DELETE). Allowed by default; --replace keeps the saved setting');
    }

    public function handle(MerchantStore $store, MerchantAuthenticationCheck $check, StdinTerminalDetector $terminal): int
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
            $read = $this->option('read');
            $write = $this->option('write');
            $access = $store->add($name, $apiKey, (bool) $this->option('replace'), is_bool($read) ? $read : null, is_bool($write) ? $write : null);
            $this->info('Merchant saved: '.$name);
            if (! $access->isFull()) {
                $this->line('Access: '.$access->label());
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

<?php

namespace App\Commands\Merchants;

use App\Console\BaseCommand;
use App\Credentials\EnvironmentVariables;
use App\Credentials\Exceptions\CredentialException;
use App\Credentials\MerchantStore;

class RemoveMerchantCommand extends BaseCommand
{
    protected $signature = 'merchants:remove {name : Saved merchant name whose local credential should be removed}';

    protected $description = 'Remove a locally saved merchant credential without deleting its Quickpay account';

    public function handle(MerchantStore $store, EnvironmentVariables $environment): int
    {
        try {
            $store->remove((string) $this->argument('name'));
        } catch (CredentialException $exception) {
            return $this->failure($exception->getMessage());
        }
        $this->info('Saved merchant removed.');
        if ($environment->get('QUICKPAY_API_KEY') !== null) {
            $this->comment('QUICKPAY_API_KEY remains configured; unset it to stop using that credential.');
        }

        return self::SUCCESS;
    }
}

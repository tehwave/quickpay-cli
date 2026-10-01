<?php

namespace App\Commands\Merchants;

use App\Console\BaseCommand;
use App\Console\Terminal\StdinTerminalDetector;
use App\Credentials\EnvironmentVariables;
use App\Credentials\Exceptions\CredentialException;
use App\Credentials\MerchantName;
use App\Credentials\MerchantStore;
use App\Credentials\ProjectMerchantFile;

class SelectMerchantCommand extends BaseCommand
{
    protected $signature = 'merchants:select
        {name? : Saved merchant name. Omit to choose interactively}
        {--local : Write only a merchant selection to .quickpay-cli.json in the current directory}';

    protected $description = 'Select the global default merchant or bind this project to a saved merchant';

    public function handle(MerchantStore $store, ProjectMerchantFile $project, EnvironmentVariables $environment, StdinTerminalDetector $terminal): int
    {
        try {
            if ($environment->get('QUICKPAY_API_KEY') !== null) {
                return $this->failure('Unset QUICKPAY_API_KEY before selecting a saved merchant.');
            }
            $config = $store->read();
            $name = $this->argument('name');
            if ($name === null) {
                if ($config['merchants'] === []) {
                    return $this->failure('No saved merchants. Run quickpay merchants:add <name> first.');
                }
                if (! $this->input->isInteractive() || ! $terminal->isTty()) {
                    return $this->failure('Specify a merchant name when selecting non-interactively.');
                }
                $names = array_keys($config['merchants']);
                sort($names, SORT_STRING);
                $name = $this->choice('Select merchant', [...$names, 'Cancel']);
                if ($name === 'Cancel') {
                    $this->line('Cancelled.');

                    return self::SUCCESS;
                }
            }
            if (! is_string($name)) {
                return $this->failure('Select exactly one merchant name.');
            }
            $name = MerchantName::validate($name);
            if (! isset($config['merchants'][$name])) {
                return $this->failure('Merchant is not saved. Run quickpay merchants:list to see saved names.');
            }
            if ($this->option('local')) {
                $project->save($name);
                $this->info('Project merchant selected: '.$name);
            } else {
                $store->select($name);
                $this->info('Global default merchant selected: '.$name);
            }

            return self::SUCCESS;
        } catch (CredentialException $exception) {
            return $this->failure($exception->getMessage());
        }
    }
}

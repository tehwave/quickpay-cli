<?php

namespace App\Commands\Merchants;

use App\Console\BaseCommand;
use App\Console\Terminal\StdinTerminalDetector;
use App\Credentials\Exceptions\CredentialException;
use App\Credentials\MerchantAccess;
use App\Credentials\MerchantName;
use App\Credentials\MerchantStore;
use Symfony\Component\Console\Input\InputOption;

class SetMerchantAccessCommand extends BaseCommand
{
    protected $signature = 'merchants:access
        {name : Saved merchant name}';

    protected $description = 'Show or change whether a saved merchant may send write requests';

    public function __construct()
    {
        parent::__construct();
        $this->addOption('write', null, InputOption::VALUE_NEGATABLE, 'Allow write requests (POST, PUT, PATCH, DELETE); --no-write makes the merchant read-only. Allowing needs an interactive terminal');
    }

    public function handle(MerchantStore $store, StdinTerminalDetector $terminal): int
    {
        try {
            $name = MerchantName::validate((string) $this->argument('name'));
            $config = $store->read();
            if (! isset($config['merchants'][$name])) {
                return $this->failure('Merchant is not saved. Run quickpay merchants:list to see saved names.');
            }

            $current = new MerchantAccess($config['merchants'][$name]['write']);
            $write = $this->option('write');
            // Allowing what is already allowed changes nothing, so it is not
            // saved and cannot undo a block saved in the meantime.
            if (! is_bool($write) || ($write && $current->write)) {
                $this->line($this->summary($name, $current));

                return self::SUCCESS;
            }

            // Making a merchant read-only is always safe. Allowing writes again
            // needs a person at the keyboard, so an automated caller cannot.
            if ($write) {
                if (! $this->input->isInteractive() || ! $terminal->isTty()) {
                    return $this->failure('Allowing write requests requires an interactive terminal.');
                }

                if (! $this->confirm('Allow write requests for '.$name.'?', false)) {
                    $this->line('Cancelled.');

                    return self::SUCCESS;
                }
            }

            $this->info($this->summary($name, $store->changeAccess($name, $write)));

            return self::SUCCESS;
        } catch (CredentialException $exception) {
            return $this->failure($exception->getMessage());
        }
    }

    private function summary(string $name, MerchantAccess $access): string
    {
        return "Access for {$name}: {$access->label()}";
    }
}

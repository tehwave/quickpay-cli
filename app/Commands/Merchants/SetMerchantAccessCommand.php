<?php

namespace App\Commands\Merchants;

use App\Console\BaseCommand;
use App\Console\Terminal\StdinTerminalDetector;
use App\Credentials\Exceptions\CredentialException;
use App\Credentials\MerchantAccess;
use App\Credentials\MerchantName;
use App\Credentials\MerchantStore;

class SetMerchantAccessCommand extends BaseCommand
{
    protected $signature = 'merchants:access
        {name : Saved merchant name}
        {--read-only : Block write requests (POST, PUT, PATCH, DELETE)}
        {--read-write : Allow write requests again. Needs an interactive terminal}';

    protected $description = 'Show or change whether a saved merchant is read-only';

    public function handle(MerchantStore $store, StdinTerminalDetector $terminal): int
    {
        try {
            $name = MerchantName::validate((string) $this->argument('name'));
            $readOnly = (bool) $this->option('read-only');
            $readWrite = (bool) $this->option('read-write');
            if ($readOnly && $readWrite) {
                return $this->failure('Choose either --read-only or --read-write.');
            }

            $config = $store->read();
            if (! isset($config['merchants'][$name])) {
                return $this->failure('Merchant is not saved. Run quickpay merchants:list to see saved names.');
            }

            $current = new MerchantAccess($config['merchants'][$name]['read_only']);
            // Allowing what is already allowed changes nothing, so it is not
            // saved and cannot undo a protection saved in the meantime.
            if (! $readOnly && (! $readWrite || ! $current->readOnly)) {
                $this->line($this->summary($name, $current));

                return self::SUCCESS;
            }

            // Making a merchant read-only is always safe. Allowing writes again
            // needs a person at the keyboard, so an automated caller cannot.
            if ($readWrite) {
                if (! $this->input->isInteractive() || ! $terminal->isTty()) {
                    return $this->failure('Allowing write requests requires an interactive terminal.');
                }

                if (! $this->confirm('Allow write requests for '.$name.'?', false)) {
                    $this->line('Cancelled.');

                    return self::SUCCESS;
                }
            }

            $this->info($this->summary($name, $store->changeAccess($name, $readOnly)));

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

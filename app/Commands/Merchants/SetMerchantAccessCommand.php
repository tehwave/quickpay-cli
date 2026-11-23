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

    protected $description = 'Show or change which Quickpay requests a saved merchant may send';

    public function __construct()
    {
        parent::__construct();
        $this->addOption('read', null, InputOption::VALUE_NEGATABLE, 'Allow read requests (GET); --no-read blocks them. Allowing needs an interactive terminal');
        $this->addOption('write', null, InputOption::VALUE_NEGATABLE, 'Allow write requests (POST, PUT, PATCH, DELETE); --no-write blocks them. Allowing needs an interactive terminal');
    }

    public function handle(MerchantStore $store, StdinTerminalDetector $terminal): int
    {
        try {
            $name = MerchantName::validate((string) $this->argument('name'));
            $config = $store->read();
            if (! isset($config['merchants'][$name])) {
                return $this->failure('Merchant is not saved. Run quickpay merchants:list to see saved names.');
            }

            $read = $this->flag('read');
            $write = $this->flag('write');
            if ($read === null && $write === null) {
                $saved = $config['merchants'][$name];
                $this->line($this->summary($name, new MerchantAccess($saved['read'], $saved['write'])));

                return self::SUCCESS;
            }

            // Blocking requests is always safe. Allowing them needs a person at
            // the keyboard, so an automated caller cannot lift a block itself.
            $allowing = array_keys(array_filter(['read' => $read, 'write' => $write], fn (?bool $flag): bool => $flag === true));
            if ($allowing !== []) {
                if (! $this->input->isInteractive() || ! $terminal->isTty()) {
                    return $this->failure('Allowing requests for a merchant requires an interactive terminal.');
                }

                if (! $this->confirm('Allow '.implode(' and ', $allowing).' requests for '.$name.'?', false)) {
                    $this->line('Cancelled.');

                    return self::SUCCESS;
                }
            }

            $this->info($this->summary($name, $store->changeAccess($name, $read, $write)));

            return self::SUCCESS;
        } catch (CredentialException $exception) {
            return $this->failure($exception->getMessage());
        }
    }

    private function flag(string $name): ?bool
    {
        $value = $this->option($name);

        return is_bool($value) ? $value : null;
    }

    private function summary(string $name, MerchantAccess $access): string
    {
        return "Access for {$name}: {$access->label()}";
    }
}

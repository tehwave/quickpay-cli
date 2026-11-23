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

            $saved = $config['merchants'][$name];
            $current = new MerchantAccess($saved['read'], $saved['write']);
            // Blocking requests is always safe. Lifting a block needs a person
            // at the keyboard, so an automated caller cannot lift one itself.
            $lifting = $current->lifts($this->flag('read'), $this->flag('write'));
            // Allowing what is already allowed is dropped, not saved, so it can
            // never undo a block that another command saves in the meantime.
            $read = $this->change('read', $lifting);
            $write = $this->change('write', $lifting);

            if ($lifting !== []) {
                if (! $this->input->isInteractive() || ! $terminal->isTty()) {
                    return $this->failure('Allowing requests for a merchant requires an interactive terminal.');
                }

                if (! $this->confirm('Allow '.implode(' and ', $lifting).' requests for '.$name.'?', false)) {
                    // A declined allow still applies any block that was asked for.
                    $read = $read === true ? null : $read;
                    $write = $write === true ? null : $write;
                    if ($read === null && $write === null) {
                        $this->line('Cancelled.');

                        return self::SUCCESS;
                    }

                    $this->line(ucfirst(implode(' and ', $lifting)).' requests stay blocked.');
                }
            }

            if ($read === null && $write === null) {
                $this->line($this->summary($name, $current));

                return self::SUCCESS;
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

    /** @param list<string> $lifting */
    private function change(string $name, array $lifting): ?bool
    {
        $flag = $this->flag($name);

        return $flag === true && ! in_array($name, $lifting, true) ? null : $flag;
    }

    private function summary(string $name, MerchantAccess $access): string
    {
        return "Access for {$name}: {$access->label()}";
    }
}

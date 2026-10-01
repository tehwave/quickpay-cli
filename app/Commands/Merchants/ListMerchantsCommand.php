<?php

namespace App\Commands\Merchants;

use App\Console\BaseCommand;
use App\Console\Output\JsonOutput;
use App\Credentials\Exceptions\CredentialException;
use App\Credentials\MerchantStore;

class ListMerchantsCommand extends BaseCommand
{
    protected $signature = 'merchants:list {--json : Write machine-readable JSON}';

    protected $description = 'List locally saved merchants and the global default';

    public function handle(MerchantStore $store): int
    {
        try {
            $config = $store->read();
        } catch (CredentialException $exception) {
            return $this->failure($exception->getMessage());
        }
        $names = array_keys($config['merchants']);
        sort($names, SORT_STRING);
        $rows = array_map(fn (string $name): array => ['name' => $name, 'default' => $config['default_merchant'] === $name], $names);
        if ($this->option('json')) {
            $this->getOutput()->write(JsonOutput::value($rows, ''));
        } elseif ($rows === []) {
            $this->line('No saved merchants. Run quickpay merchants:add <name> first.');
        } else {
            $this->table(['Merchant', 'Global default'], array_map(fn (array $row): array => [$row['name'], $row['default'] ? 'yes' : ''], $rows));
        }

        return self::SUCCESS;
    }
}

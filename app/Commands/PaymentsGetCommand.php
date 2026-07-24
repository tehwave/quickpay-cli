<?php

namespace App\Commands;

use App\Commands\Concerns\InteractsWithQuickpay;
use App\Commands\Concerns\WritesPaymentOutput;
use App\Credentials\CredentialStore;
use App\Quickpay\QuickpayClient;
use App\Quickpay\QuickpayClientFactory;
use Illuminate\Console\Command;
use InvalidArgumentException;

class PaymentsGetCommand extends Command
{
    use InteractsWithQuickpay;
    use WritesPaymentOutput;

    protected $signature = 'payments:get {id} {--operations-size=} {--json}';

    protected $description = 'Get a Quickpay payment';

    public function handle(CredentialStore $credentials, QuickpayClientFactory $clients): int
    {
        return $this->withQuickpay($credentials, $clients, function (QuickpayClient $client, string $apiKey): int {
            $id = $this->positiveInteger($this->argument('id'), 'id');
            $query = [];
            $operationsSize = $this->option('operations-size');

            if ($operationsSize !== null) {
                $query['operations_size'] = $this->nonNegativeInteger($operationsSize, 'operations-size');
            }

            $response = $client->get("/payments/{$id}", $query);

            if (! $response->successful()) {
                return $this->responseFailure($response, $apiKey);
            }

            if ($this->option('json')) {
                $this->writeOriginalJson($response);

                return self::SUCCESS;
            }

            if (! is_array($response->json) || array_is_list($response->json)) {
                throw new InvalidArgumentException('Quickpay returned an invalid payment.');
            }

            $this->writePaymentDetails($response->json);
            $operations = $response->json['operations'] ?? [];

            if (is_array($operations) && array_is_list($operations)) {
                $this->writeOperationsTable($operations);
            }

            return self::SUCCESS;
        });
    }
}

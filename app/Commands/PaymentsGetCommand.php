<?php

namespace App\Commands;

use App\Commands\Concerns\InteractsWithQuickpay;
use App\Commands\Concerns\WritesPaymentOutput;
use App\Credentials\CredentialStore;
use App\Quickpay\QuickpayClient;
use App\Quickpay\QuickpayClientFactory;
use Illuminate\Console\Command;

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

            $payment = $this->jsonObject($response, 'Quickpay returned an invalid payment.');

            if ($this->option('json')) {
                $this->writeOriginalJson($response, $apiKey);

                return self::SUCCESS;
            }

            $this->writePaymentDetails($payment, $apiKey);
            $operations = $payment['operations'] ?? [];

            if (is_array($operations) && array_is_list($operations)) {
                $this->writeOperationsTable($operations, $apiKey);
            }

            return self::SUCCESS;
        });
    }
}

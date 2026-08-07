<?php

namespace App\Commands\Payments;

use App\Console\AuthenticatedCommand;
use App\Console\Input\IntegerInput;
use App\Console\Output\PaymentPresenter;
use App\Console\Output\ResponseBodySanitizer;
use App\Quickpay\AuthenticatedQuickpay;
use App\Quickpay\AuthenticatedQuickpayFactory;

class GetPaymentCommand extends AuthenticatedCommand
{
    protected $signature = 'payments:get {id} {--operations-size=} {--json}';

    protected $description = 'Get a Quickpay payment';

    public function handle(AuthenticatedQuickpayFactory $quickpay, PaymentPresenter $presenter): int
    {
        return $this->withQuickpay($quickpay, function (AuthenticatedQuickpay $authenticated) use ($presenter): int {
            $apiKey = $authenticated->apiKey->value();
            $id = IntegerInput::positive($this->argument('id'), 'id');
            $query = [];
            $operationsSize = $this->option('operations-size');

            if ($operationsSize !== null) {
                $query['operations_size'] = IntegerInput::nonNegative($operationsSize, 'operations-size');
            }

            $result = $authenticated->payments->get($id, $query);
            $payment = $result->data;

            if ($this->option('json')) {
                $this->getOutput()->write(ResponseBodySanitizer::json($result->response->rawBody, $apiKey));

                return self::SUCCESS;
            }

            $this->table(['Field', 'Value'], $presenter->details($payment, $apiKey));
            $operations = $payment['operations'] ?? [];

            if (is_array($operations) && array_is_list($operations)) {
                if ($operations !== []) {
                    $this->newLine();
                    $this->line('Operations');
                    $this->table(
                        ['ID', 'Type', 'Amount', 'Pending', 'Quickpay status', 'Acquirer status', 'Callback', 'Created'],
                        $presenter->operations($operations, $apiKey),
                    );
                }
            }

            return self::SUCCESS;
        });
    }
}

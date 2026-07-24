<?php

namespace App\Commands;

class PaymentsRefundCommand extends PaymentMutationCommand
{
    protected $signature = 'payments:refund {id} {amount} {--vat-rate=} {--synchronized} {--callback-url=} {--yes} {--json}';

    protected $description = 'Refund a Quickpay payment';

    protected function operation(): string
    {
        return 'refund';
    }

    protected function mutationBody(): array
    {
        $body = ['amount' => $this->positiveInteger($this->argument('amount'), 'amount')];
        $vatRate = $this->option('vat-rate');

        if ($vatRate !== null) {
            $body['vat_rate'] = $this->nonNegativeNumber($vatRate, 'vat-rate');
        }

        return $body;
    }
}

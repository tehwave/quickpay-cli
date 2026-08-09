<?php

namespace App\Commands\Payments;

use App\Console\Input\IntegerInput;

class RefundPaymentCommand extends AbstractPaymentMutationCommand
{
    protected $signature = 'payments:refund
        {id : Quickpay payment ID}
        {amount : Refund amount as a positive integer in minor units}
        {--vat-rate= : Optional non-negative VAT rate}
        {--synchronized : Request a synchronized Quickpay response}
        {--callback-url= : Callback URL for Quickpay servers (not localhost)}
        {--yes : Skip the interactive confirmation}
        {--json : Write machine-readable JSON}';

    protected $description = 'Refund a Quickpay payment';

    protected function operation(): string
    {
        return 'refund';
    }

    protected function mutationBody(): array
    {
        $body = ['amount' => IntegerInput::positive($this->argument('amount'), 'amount')];
        $vatRate = $this->option('vat-rate');

        if ($vatRate !== null) {
            $body['vat_rate'] = IntegerInput::nonNegativeNumber($vatRate, 'vat-rate');
        }

        return $body;
    }
}

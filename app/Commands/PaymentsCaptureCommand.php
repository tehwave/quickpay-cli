<?php

namespace App\Commands;

class PaymentsCaptureCommand extends PaymentMutationCommand
{
    protected $signature = 'payments:capture {id} {amount} {--synchronized} {--callback-url= : Callback URL for Quickpay servers (not localhost)} {--yes} {--json}';

    protected $description = 'Capture an authorized Quickpay payment';

    protected function operation(): string
    {
        return 'capture';
    }

    protected function mutationBody(): array
    {
        return ['amount' => $this->positiveInteger($this->argument('amount'), 'amount')];
    }
}

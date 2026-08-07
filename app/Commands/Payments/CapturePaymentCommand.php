<?php

namespace App\Commands\Payments;

use App\Console\Input\IntegerInput;

class CapturePaymentCommand extends AbstractPaymentMutationCommand
{
    protected $signature = 'payments:capture {id} {amount} {--synchronized} {--callback-url= : Callback URL for Quickpay servers (not localhost)} {--yes} {--json}';

    protected $description = 'Capture an authorized Quickpay payment';

    protected function operation(): string
    {
        return 'capture';
    }

    protected function mutationBody(): array
    {
        return ['amount' => IntegerInput::positive($this->argument('amount'), 'amount')];
    }
}

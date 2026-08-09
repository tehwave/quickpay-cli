<?php

namespace App\Commands\Payments;

use App\Console\Input\IntegerInput;

class CapturePaymentCommand extends AbstractPaymentMutationCommand
{
    protected $signature = 'payments:capture
        {id : Quickpay payment ID}
        {amount : Capture amount as a positive integer in minor units}
        {--synchronized : Request a synchronized Quickpay response}
        {--callback-url= : Callback URL for Quickpay servers (not localhost)}
        {--yes : Skip the interactive confirmation}
        {--json : Write machine-readable JSON}';

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

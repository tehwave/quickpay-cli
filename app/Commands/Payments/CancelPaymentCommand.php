<?php

namespace App\Commands\Payments;

class CancelPaymentCommand extends AbstractPaymentMutationCommand
{
    protected $signature = 'payments:cancel
        {id : Quickpay payment ID}
        {--synchronized : Request a synchronized Quickpay response}
        {--callback-url= : Callback URL for Quickpay servers (not localhost)}
        {--yes : Skip the interactive confirmation}
        {--json : Write machine-readable JSON}';

    protected $description = 'Cancel a Quickpay payment';

    protected function operation(): string
    {
        return 'cancel';
    }

    protected function mutationBody(): array
    {
        return [];
    }
}

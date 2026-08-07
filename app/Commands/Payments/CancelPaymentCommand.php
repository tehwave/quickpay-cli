<?php

namespace App\Commands\Payments;

class CancelPaymentCommand extends AbstractPaymentMutationCommand
{
    protected $signature = 'payments:cancel {id} {--synchronized} {--callback-url= : Callback URL for Quickpay servers (not localhost)} {--yes} {--json}';

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

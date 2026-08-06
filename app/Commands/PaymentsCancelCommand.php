<?php

namespace App\Commands;

class PaymentsCancelCommand extends PaymentMutationCommand
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

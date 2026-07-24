<?php

namespace App\Commands;

use App\Commands\Concerns\WritesErrors;
use App\Credentials\CredentialStore;
use App\Credentials\Exceptions\CredentialStoreException;
use Illuminate\Console\Command;

class LogoutCommand extends Command
{
    use WritesErrors;

    protected $signature = 'logout';

    protected $description = 'Remove the stored Quickpay credential';

    public function handle(CredentialStore $credentials): int
    {
        try {
            $removed = $credentials->forgetStored();
        } catch (CredentialStoreException $exception) {
            return $this->failure($exception->getMessage());
        }

        if ($removed) {
            $this->info('Stored credentials removed.');
        } else {
            $this->line('No stored credentials found.');
        }

        $environmentKey = getenv('QUICKPAY_API_KEY');

        if (is_string($environmentKey) && trim($environmentKey) !== '') {
            $this->comment('QUICKPAY_API_KEY is still active; unset it to fully log out.');
        }

        return self::SUCCESS;
    }
}

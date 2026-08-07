<?php

namespace App\Commands\Authentication;

use App\Console\BaseCommand;
use App\Credentials\ApiKeyResolver;
use App\Credentials\CredentialFile;
use App\Credentials\Exceptions\CredentialException;

class LogoutCommand extends BaseCommand
{
    protected $signature = 'logout';

    protected $description = 'Remove the stored Quickpay credential';

    public function handle(CredentialFile $credentials, ApiKeyResolver $resolver): int
    {
        try {
            $removed = $credentials->forget();
        } catch (CredentialException $exception) {
            return $this->failure($exception->getMessage());
        }

        if ($removed) {
            $this->info('Stored credentials removed.');
        } else {
            $this->line('No stored credentials found.');
        }

        if ($resolver->resolve()?->source() === 'environment') {
            $this->comment('QUICKPAY_API_KEY is still active; unset it to fully log out.');
        }

        return self::SUCCESS;
    }
}

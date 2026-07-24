<?php

namespace App\Commands;

use App\Commands\Concerns\WritesErrors;
use App\Credentials\CredentialRedactor;
use App\Credentials\CredentialStore;
use App\Credentials\Exceptions\CredentialStoreException;
use App\Quickpay\Exceptions\QuickpayRequestException;
use App\Quickpay\QuickpayClient;
use App\Quickpay\QuickpayClientFactory;
use Illuminate\Console\Command;

class LoginCommand extends Command
{
    use WritesErrors;

    protected $signature = 'login';

    protected $description = 'Validate and securely store a Quickpay merchant API key';

    public function handle(CredentialStore $credentials, QuickpayClientFactory $clients): int
    {
        $answer = $this->secret('API key');
        $apiKey = is_string($answer) ? trim($answer) : '';

        if ($apiKey === '') {
            return $this->failure('API key cannot be empty.');
        }

        try {
            $response = $clients->make($apiKey)->get('/ping');
        } catch (QuickpayRequestException $exception) {
            return $this->failure($exception->getMessage());
        }

        if (! $response->successful()) {
            return $this->failure('Authentication failed: '.CredentialRedactor::redact($response->errorSummary(), $apiKey));
        }

        $scope = is_array($response->json) && is_string($response->json['scope'] ?? null)
            ? $response->json['scope']
            : null;

        if ($scope !== 'merchant') {
            return $this->failure('This credential does not have merchant scope. Only merchant API keys are supported.');
        }

        try {
            $credentials->save($apiKey);
        } catch (CredentialStoreException $exception) {
            return $this->failure($exception->getMessage());
        }

        $this->info('Credentials stored securely.');
        $this->line('Scope: merchant');
        $this->line('Version: '.QuickpayClient::API_VERSION);

        return self::SUCCESS;
    }
}

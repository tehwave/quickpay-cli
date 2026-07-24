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

class AuthCommand extends Command
{
    use WritesErrors;

    protected $signature = 'auth';

    protected $description = 'Show the active Quickpay authentication status';

    public function handle(CredentialStore $credentials, QuickpayClientFactory $clients): int
    {
        try {
            $source = $credentials->source();
            $apiKey = $credentials->apiKey();
        } catch (CredentialStoreException $exception) {
            return $this->failure($exception->getMessage());
        }

        $this->line("Credential source: {$source}");
        $this->line('API base URL: '.QuickpayClient::BASE_URL);
        $this->line('API version: '.QuickpayClient::API_VERSION);

        if ($apiKey === null) {
            $this->line('Scope: not authenticated');
            $this->comment('Run quickpay login to store a merchant API key.');

            return self::SUCCESS;
        }

        try {
            $response = $clients->make($apiKey)->get('/ping');
        } catch (QuickpayRequestException $exception) {
            return $this->failure($exception->getMessage());
        }

        if (! $response->successful()) {
            return $this->failure('Authentication check failed: '.CredentialRedactor::redact($response->errorSummary(), $apiKey));
        }

        $scope = is_array($response->json) && is_string($response->json['scope'] ?? null)
            ? $response->json['scope']
            : null;

        if ($scope === null || $scope === '') {
            return $this->failure('Quickpay authentication check did not return an active scope.');
        }

        $this->line("Scope: {$scope}");

        return self::SUCCESS;
    }
}

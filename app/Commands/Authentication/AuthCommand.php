<?php

namespace App\Commands\Authentication;

use App\Console\BaseCommand;
use App\Console\Output\JsonOutput;
use App\Credentials\ApiKey;
use App\Credentials\ApiKeyResolver;
use App\Credentials\Exceptions\CredentialException;
use App\Quickpay\AuthenticatedQuickpayFactory;
use App\Quickpay\Exceptions\QuickpayRequestException;
use App\Quickpay\QuickpayClient;

class AuthCommand extends BaseCommand
{
    protected $signature = 'auth
        {--check : Exit non-zero when no active credential is configured}
        {--json : Write machine-readable JSON}';

    protected $description = 'Show the active Quickpay authentication status';

    public function handle(ApiKeyResolver $credentials, AuthenticatedQuickpayFactory $quickpay): int
    {
        try {
            $apiKey = $credentials->resolve();
        } catch (CredentialException $exception) {
            return $this->failure($exception->getMessage());
        }

        if (! $this->option('json')) {
            $this->writeHumanStatusHeader($apiKey);
        }

        if ($apiKey === null) {
            if ($this->option('json')) {
                $this->writeJsonStatus(null, null);
            } else {
                $this->line('Scope: not authenticated');
                $this->comment('Run quickpay login to store a merchant API key.');
            }

            return $this->option('check') ? self::FAILURE : self::SUCCESS;
        }

        try {
            $response = $quickpay->from($apiKey)->client->get('/ping');
        } catch (QuickpayRequestException $exception) {
            return $this->failure($exception->getMessage());
        }

        if (! $response->successful()) {
            return $this->failure('Authentication check failed: '.$apiKey->redact($response->errorSummary()));
        }

        $scope = is_array($response->json) && is_string($response->json['scope'] ?? null)
            ? $response->json['scope']
            : null;

        if ($scope === null || $scope === '') {
            return $this->failure('Quickpay authentication check did not return an active scope.');
        }

        if ($apiKey->appearsIn($scope)
            || preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', $scope) !== 1) {
            return $this->failure('Quickpay returned an invalid scope.');
        }

        if ($this->option('json')) {
            $this->writeJsonStatus($apiKey, $scope);
        } else {
            $this->line("Scope: {$scope}");
        }

        return self::SUCCESS;
    }

    private function writeHumanStatusHeader(?ApiKey $apiKey): void
    {
        $this->line('Credential source: '.($apiKey?->source() ?? 'none'));
        $this->line('API base URL: '.QuickpayClient::BASE_URL);
        $this->line('API version: '.QuickpayClient::API_VERSION);
    }

    private function writeJsonStatus(?ApiKey $apiKey, ?string $scope): void
    {
        $this->getOutput()->write(JsonOutput::value([
            'authenticated' => $scope !== null,
            'credential_source' => $apiKey?->source(),
            'api_base_url' => QuickpayClient::BASE_URL,
            'api_version' => QuickpayClient::API_VERSION,
            'scope' => $scope,
        ], ''));
    }
}

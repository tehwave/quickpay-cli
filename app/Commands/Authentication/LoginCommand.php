<?php

namespace App\Commands\Authentication;

use App\Console\BaseCommand;
use App\Credentials\ApiKey;
use App\Credentials\CredentialFile;
use App\Credentials\Exceptions\CredentialException;
use App\Quickpay\AuthenticatedQuickpayFactory;
use App\Quickpay\Exceptions\QuickpayRequestException;
use App\Quickpay\QuickpayClient;

class LoginCommand extends BaseCommand
{
    protected $signature = 'login';

    protected $description = 'Validate and securely store a Quickpay merchant API key';

    public function handle(CredentialFile $credentials, AuthenticatedQuickpayFactory $quickpay): int
    {
        $answer = $this->secret('API key');
        $apiKey = is_string($answer) ? trim($answer) : '';

        if ($apiKey === '') {
            return $this->failure('API key cannot be empty.');
        }

        $credential = new ApiKey($apiKey, 'provided');

        try {
            $response = $quickpay->from($credential)->client->get('/ping');
        } catch (QuickpayRequestException $exception) {
            return $this->failure($exception->getMessage());
        }

        if (! $response->successful()) {
            return $this->failure('Authentication failed: '.$credential->redact($response->errorSummary()));
        }

        $scope = is_array($response->json) && is_string($response->json['scope'] ?? null)
            ? $response->json['scope']
            : null;

        if ($scope !== 'merchant') {
            return $this->failure('This credential does not have merchant scope. Only merchant API keys are supported.');
        }

        try {
            $credentials->save($apiKey);
        } catch (CredentialException $exception) {
            return $this->failure($exception->getMessage());
        }

        $this->info('Credentials stored securely.');
        $this->line('Scope: merchant');
        $this->line('Version: '.QuickpayClient::API_VERSION);

        return self::SUCCESS;
    }
}

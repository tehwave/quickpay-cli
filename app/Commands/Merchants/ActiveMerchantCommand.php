<?php

namespace App\Commands\Merchants;

use App\Console\AuthenticatedCommand;
use App\Console\Output\JsonOutput;
use App\Credentials\ApiKeyResolver;
use App\Credentials\Exceptions\CredentialException;
use App\Quickpay\Exceptions\QuickpayRequestException;
use App\Quickpay\MerchantAuthenticationCheck;
use App\Quickpay\QuickpayClient;
use UnexpectedValueException;

class ActiveMerchantCommand extends AuthenticatedCommand
{
    protected $signature = 'merchants:active
        {--check : Verify API access and fail when no valid merchant credential is available}
        {--json : Write machine-readable JSON}';

    protected $description = 'Show the active merchant and optionally verify API access';

    public function handle(ApiKeyResolver $resolver, MerchantAuthenticationCheck $check): int
    {
        try {
            $merchant = $this->option('merchant');
            $key = $resolver->resolve(is_string($merchant) ? $merchant : null);
        } catch (CredentialException $exception) {
            return $this->failure($exception->getMessage());
        }
        $requested = (bool) $this->option('check');
        $error = null;
        if ($requested) {
            if ($key === null) {
                $error = 'No Quickpay credentials found. Run quickpay merchants:add <name> first.';
            } else {
                try {
                    $check->check($key);
                } catch (QuickpayRequestException|UnexpectedValueException $exception) {
                    $error = $key->redact($exception->getMessage());
                }
            }
        }
        $authenticated = $requested ? $error === null : null;
        $status = [
            'merchant' => $key?->merchant(),
            'selection_source' => $key?->selectionSource(),
            'credential_source' => $key?->source(),
            'configured' => $key !== null,
            'checked' => $requested,
            'authenticated' => $authenticated,
            'api_base_url' => QuickpayClient::BASE_URL,
            'api_version' => QuickpayClient::API_VERSION,
            'scope' => $authenticated === true ? 'merchant' : null,
        ];
        if ($this->option('json')) {
            $this->getOutput()->write(JsonOutput::value($status, ''));
        } else {
            $this->line('Merchant: '.($status['merchant'] ?? 'not associated with a saved merchant'));
            $this->line('Selection source: '.($status['selection_source'] ?? 'none'));
            $this->line('Credential source: '.($status['credential_source'] ?? 'none'));
            $this->line('Credentials configured: '.($status['configured'] ? 'yes' : 'no'));
            $this->line('API base URL: '.$status['api_base_url']);
            $this->line('API version: '.$status['api_version']);
            $this->line('API access: '.($requested ? ($authenticated ? 'verified' : 'failed') : 'not checked'));
        }

        return $error === null ? self::SUCCESS : $this->failure($error);
    }
}

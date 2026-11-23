<?php

namespace App\Commands\Merchants;

use App\Console\AuthenticatedCommand;
use App\Console\Output\JsonOutput;
use App\Credentials\ApiKey;
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

        $checkRequested = (bool) $this->option('check');
        $error = $checkRequested ? $this->authenticationError($key, $check) : null;
        $authenticated = $checkRequested ? $error === null : null;
        $status = [
            'merchant' => $key?->merchant(),
            'selection_source' => $key?->selectionSource(),
            'credential_source' => $key?->source(),
            'configured' => $key !== null,
            'read_only' => $key?->access()->readOnly,
            'checked' => $checkRequested,
            'authenticated' => $authenticated,
            'api_base_url' => QuickpayClient::BASE_URL,
            'api_version' => QuickpayClient::API_VERSION,
            'scope' => $authenticated === true ? 'merchant' : null,
        ];

        if ($this->option('json')) {
            $this->getOutput()->write(JsonOutput::value($status, ''));
        } else {
            $this->line('Merchant: '.($status['merchant'] ?? ($key === null ? 'none' : 'not associated with a saved merchant')));
            $this->line('Selection source: '.($status['selection_source'] ?? 'none'));
            $this->line('Credential source: '.($status['credential_source'] ?? 'none'));
            if ($key !== null && $key->access()->readOnly) {
                $this->line('Access: '.$key->access()->label());
            }
            $this->line('Credentials configured: '.($status['configured'] ? 'yes' : 'no'));
            $this->line('API access: '.match ($authenticated) {
                true => 'verified',
                false => 'failed',
                null => 'not checked',
            });
        }

        return $error === null ? self::SUCCESS : $this->failure($error);
    }

    private function authenticationError(?ApiKey $key, MerchantAuthenticationCheck $check): ?string
    {
        if ($key === null) {
            return self::NO_MERCHANT_SELECTED;
        }

        try {
            $check->check($key);

            return null;
        } catch (QuickpayRequestException|UnexpectedValueException $exception) {
            return $key->redact($exception->getMessage());
        }
    }
}

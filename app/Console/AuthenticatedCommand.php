<?php

namespace App\Console;

use App\Console\Output\ResponseBodySanitizer;
use App\Credentials\Exceptions\CredentialException;
use App\Quickpay\AuthenticatedQuickpay;
use App\Quickpay\AuthenticatedQuickpayFactory;
use App\Quickpay\Exceptions\QuickpayRequestException;
use App\Quickpay\QuickpayResponse;
use Closure;
use InvalidArgumentException;
use UnexpectedValueException;

abstract class AuthenticatedCommand extends BaseCommand
{
    /** @param Closure(AuthenticatedQuickpay): int $callback */
    protected function withQuickpay(AuthenticatedQuickpayFactory $quickpay, Closure $callback): int
    {
        try {
            $authenticated = $quickpay->resolve();
        } catch (CredentialException $exception) {
            return $this->failure($exception->getMessage());
        }

        if ($authenticated === null) {
            return $this->failure('No Quickpay credentials found. Run quickpay login first.');
        }

        try {
            return $callback($authenticated);
        } catch (InvalidArgumentException|QuickpayRequestException|UnexpectedValueException $exception) {
            return $this->failure($authenticated->safeLine($exception->getMessage()));
        }
    }

    protected function responseFailure(QuickpayResponse $response, string $apiKey): int
    {
        return $this->failure(ResponseBodySanitizer::terminalLine($response->errorSummary(), $apiKey));
    }
}

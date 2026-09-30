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
use Symfony\Component\Console\Input\InputOption;
use UnexpectedValueException;

abstract class AuthenticatedCommand extends BaseCommand
{
    public function __construct()
    {
        parent::__construct();
        $this->addOption('merchant', null, InputOption::VALUE_REQUIRED, 'Saved merchant to select for this command');
    }

    /** @param Closure(AuthenticatedQuickpay): int $callback */
    protected function withQuickpay(AuthenticatedQuickpayFactory $quickpay, Closure $callback): int
    {
        try {
            $merchant = $this->option('merchant');
            $authenticated = $quickpay->resolve(is_string($merchant) ? $merchant : null);
        } catch (CredentialException $exception) {
            return $this->failure($exception->getMessage());
        }

        if ($authenticated === null) {
            return $this->failure('No Quickpay credentials found. Run quickpay merchants:add <name> first.');
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

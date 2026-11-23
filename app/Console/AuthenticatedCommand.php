<?php

namespace App\Console;

use App\Console\Output\ResponseBodySanitizer;
use App\Credentials\Exceptions\CredentialException;
use App\Credentials\Exceptions\MerchantAccessException;
use App\Quickpay\AuthenticatedQuickpay;
use App\Quickpay\AuthenticatedQuickpayFactory;
use App\Quickpay\Exceptions\QuickpayRequestException;
use App\Quickpay\QuickpayResponse;
use Closure;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use UnexpectedValueException;

abstract class AuthenticatedCommand extends BaseCommand
{
    // Covers both an empty store and saved merchants without a global default.
    protected const NO_MERCHANT_SELECTED = 'No merchant selected. Run quickpay merchants:add <name>, or quickpay merchants:select <name> to choose a saved default.';

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
            return $this->failure(self::NO_MERCHANT_SELECTED);
        }

        try {
            return $callback($authenticated);
        } catch (InvalidArgumentException|MerchantAccessException|QuickpayRequestException|UnexpectedValueException $exception) {
            return $this->failure($authenticated->safeLine($exception->getMessage()));
        }
    }

    protected function responseFailure(QuickpayResponse $response, string $apiKey): int
    {
        return $this->failure(ResponseBodySanitizer::terminalLine($response->errorSummary(), $apiKey));
    }

    protected function writeMerchantContext(AuthenticatedQuickpay $authenticated): void
    {
        foreach ([
            'Merchant: '.($authenticated->apiKey->merchant() ?? 'not associated with a saved merchant'),
            'Credential source: '.$authenticated->apiKey->source(),
        ] as $message) {
            $this->writeSafetyLine($authenticated->safeLine($message));
        }
    }

    protected function writeSafetyLine(string $message): void
    {
        $output = $this->input->hasOption('json') && $this->input->getOption('json')
            ? $this->getOutput()->getErrorStyle()
            : $this->getOutput();

        $output->writeln($message, OutputInterface::OUTPUT_RAW);
    }
}

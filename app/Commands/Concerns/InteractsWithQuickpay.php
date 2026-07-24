<?php

namespace App\Commands\Concerns;

use App\Credentials\CredentialStore;
use App\Credentials\Exceptions\CredentialStoreException;
use App\Quickpay\Exceptions\QuickpayRequestException;
use App\Quickpay\QuickpayClient;
use App\Quickpay\QuickpayClientFactory;
use App\Quickpay\QuickpayResponse;
use App\Support\ResponseBodySanitizer;
use Closure;
use InvalidArgumentException;

trait InteractsWithQuickpay
{
    use WritesErrors;

    /** @param Closure(QuickpayClient, string): int $callback */
    protected function withQuickpay(
        CredentialStore $credentials,
        QuickpayClientFactory $clients,
        Closure $callback,
    ): int {
        try {
            $apiKey = $credentials->apiKey();
        } catch (CredentialStoreException $exception) {
            return $this->failure($exception->getMessage());
        }

        if ($apiKey === null) {
            return $this->failure('No Quickpay credentials found. Run quickpay login first.');
        }

        try {
            return $callback($clients->make($apiKey), $apiKey);
        } catch (InvalidArgumentException|QuickpayRequestException $exception) {
            return $this->failure(ResponseBodySanitizer::terminalLine($exception->getMessage(), $apiKey));
        }
    }

    protected function responseFailure(QuickpayResponse $response, string $apiKey): int
    {
        return $this->failure(ResponseBodySanitizer::terminalLine($response->errorSummary(), $apiKey));
    }

    protected function positiveInteger(mixed $value, string $name): int
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException("{$name} must be a positive integer.");
        }

        $value = (string) $value;

        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1 || $integer === false) {
            throw new InvalidArgumentException("{$name} must be a positive integer.");
        }

        return $integer;
    }

    protected function nonNegativeInteger(mixed $value, string $name): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ((! is_string($value) && ! is_int($value))
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', (string) $value) !== 1
            || $integer === false) {
            throw new InvalidArgumentException("{$name} must be a non-negative integer.");
        }

        return $integer;
    }

    protected function nonNegativeNumber(mixed $value, string $name): int|float
    {
        if ((! is_string($value) && ! is_int($value) && ! is_float($value))
            || preg_match('/\A(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)\z/D', (string) $value) !== 1
            || ! is_numeric($value)) {
            throw new InvalidArgumentException("{$name} must be a non-negative number.");
        }

        $number = str_contains((string) $value, '.') ? (float) $value : (int) $value;

        if (! is_finite((float) $number) || $number < 0) {
            throw new InvalidArgumentException("{$name} must be a non-negative number.");
        }

        return $number;
    }
}

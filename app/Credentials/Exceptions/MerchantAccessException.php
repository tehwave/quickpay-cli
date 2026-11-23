<?php

namespace App\Credentials\Exceptions;

/**
 * A write request from a read-only merchant.
 *
 * It deliberately is not a QuickpayRequestException: callback polling retries
 * those, and a blocked request must stop the command instead.
 */
final class MerchantAccessException extends CredentialException
{
    public static function blocked(string $method): self
    {
        $method = strtoupper($method);

        return new self("The selected merchant is read-only, so the {$method} request was not sent.");
    }
}

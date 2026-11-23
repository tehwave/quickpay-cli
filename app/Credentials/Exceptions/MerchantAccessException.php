<?php

namespace App\Credentials\Exceptions;

/**
 * A request the selected merchant's access flags do not allow.
 *
 * It deliberately is not a QuickpayRequestException: callback polling retries
 * those, and a blocked request must stop the command instead.
 */
final class MerchantAccessException extends CredentialException
{
    public static function blocked(string $method): self
    {
        $method = strtoupper($method);
        $kind = $method === 'GET' ? 'read' : 'write';

        return new self("The selected merchant does not allow {$kind} requests, so the {$method} request was not sent.");
    }
}

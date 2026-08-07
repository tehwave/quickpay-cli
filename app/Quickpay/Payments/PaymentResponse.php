<?php

namespace App\Quickpay\Payments;

use App\Quickpay\QuickpayResponse;
use InvalidArgumentException;
use JsonException;
use stdClass;

final class PaymentResponse
{
    /** @return array<int, mixed> */
    public static function list(QuickpayResponse $response, string $message): array
    {
        [$decoded, $associative] = self::decode($response, $message);

        if (! is_array($decoded) || ! is_array($associative)) {
            throw new InvalidArgumentException($message);
        }

        return $associative;
    }

    /** @return array<string, mixed> */
    public static function object(QuickpayResponse $response, string $message): array
    {
        [$decoded, $associative] = self::decode($response, $message);

        if (! $decoded instanceof stdClass || ! is_array($associative)) {
            throw new InvalidArgumentException($message);
        }

        return $associative;
    }

    /** @return array{0: mixed, 1: mixed} */
    private static function decode(QuickpayResponse $response, string $message): array
    {
        try {
            return [
                json_decode($response->rawBody, flags: JSON_THROW_ON_ERROR),
                json_decode($response->rawBody, true, flags: JSON_THROW_ON_ERROR),
            ];
        } catch (JsonException) {
            throw new InvalidArgumentException($message);
        }
    }
}

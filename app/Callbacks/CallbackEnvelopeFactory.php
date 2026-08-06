<?php

namespace App\Callbacks;

use App\Quickpay\QuickpayClient;
use App\Quickpay\QuickpayResponse;
use App\Support\ResponseBodySanitizer;
use UnexpectedValueException;

/**
 * Converts a current Quickpay payment response into a signed callback.
 *
 * Sanitization happens before signing because the receiver must verify the
 * exact bytes it receives. Signing the unsanitized API response and redacting
 * afterwards would both invalidate the checksum and risk leaking credentials.
 */
final class CallbackEnvelopeFactory
{
    public function make(
        QuickpayResponse $response,
        string $apiKey,
        string $privateKey,
        ?string $operationId = null,
    ): CallbackEnvelope {
        $resource = json_decode($response->rawBody);

        if (! is_object($resource)) {
            throw new UnexpectedValueException('Quickpay returned a callback resource that is not a payment object.');
        }

        $paymentId = $this->identifier($resource->id ?? null, 'payment ID');
        $merchantId = $this->identifier($resource->merchant_id ?? null, 'merchant ID');
        $orderId = isset($resource->order_id) && is_scalar($resource->order_id)
            ? (string) $resource->order_id
            : null;
        $body = ResponseBodySanitizer::json($response->rawBody, $apiKey);

        return new CallbackEnvelope(
            paymentId: $paymentId,
            orderId: $orderId,
            body: $body,
            headers: [
                'Content-Type' => 'application/json',
                'QuickPay-Resource-Type' => 'Payment',
                'QuickPay-Account-ID' => $merchantId,
                'QuickPay-API-Version' => QuickpayClient::API_VERSION,
                'QuickPay-Checksum-Sha256' => hash_hmac('sha256', $body, $privateKey),
            ],
            operationId: $operationId,
        );
    }

    private function identifier(mixed $value, string $name): string
    {
        if ((! is_int($value) && ! is_string($value)) || (string) $value === '') {
            throw new UnexpectedValueException("Quickpay returned a payment without a valid {$name}.");
        }

        return (string) $value;
    }
}

<?php

namespace App\Commands\Payments;

use App\Console\AuthenticatedCommand;
use App\Console\Input\KeyValueParser;
use App\Console\Output\PaymentPresenter;
use App\Console\Output\ResponseBodySanitizer;
use App\Quickpay\AuthenticatedQuickpay;
use App\Quickpay\AuthenticatedQuickpayFactory;
use InvalidArgumentException;

class CreatePaymentCommand extends AuthenticatedCommand
{
    protected $signature = 'payments:create {order-id} {currency=DKK} {--field=*} {--json}';

    protected $description = 'Create a Quickpay payment';

    public function handle(AuthenticatedQuickpayFactory $quickpay, PaymentPresenter $presenter): int
    {
        return $this->withQuickpay($quickpay, function (AuthenticatedQuickpay $authenticated) use ($presenter): int {
            $apiKey = $authenticated->apiKey->value();
            $orderId = (string) $this->argument('order-id');
            $length = function_exists('mb_strlen') ? mb_strlen($orderId) : strlen($orderId);

            if ($length < 4 || $length > 20) {
                throw new InvalidArgumentException('order-id must contain 4 through 20 characters.');
            }

            $currency = strtoupper((string) $this->argument('currency'));

            if (preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
                throw new InvalidArgumentException('currency must contain exactly three ASCII letters.');
            }

            $fields = KeyValueParser::parse($this->fieldOptions());
            $body = [
                ...$fields,
                'order_id' => $orderId,
                'currency' => $currency,
            ];
            $result = $authenticated->payments->create($body);
            $payment = $result->data;

            if ($this->option('json')) {
                $this->getOutput()->write(ResponseBodySanitizer::json($result->response->rawBody, $apiKey));

                return self::SUCCESS;
            }

            $this->table(['Field', 'Value'], $presenter->details($payment, $apiKey));

            return self::SUCCESS;
        });
    }

    /** @return array<int, string> */
    private function fieldOptions(): array
    {
        $fields = $this->option('field');
        $strings = [];

        foreach ($fields as $field) {
            if (is_string($field)) {
                $strings[] = $field;
            }
        }

        return $strings;
    }
}

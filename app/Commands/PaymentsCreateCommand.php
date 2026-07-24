<?php

namespace App\Commands;

use App\Commands\Concerns\InteractsWithQuickpay;
use App\Commands\Concerns\WritesPaymentOutput;
use App\Credentials\CredentialStore;
use App\Quickpay\QuickpayClient;
use App\Quickpay\QuickpayClientFactory;
use App\Support\KeyValueParser;
use Illuminate\Console\Command;
use InvalidArgumentException;

class PaymentsCreateCommand extends Command
{
    use InteractsWithQuickpay;
    use WritesPaymentOutput;

    protected $signature = 'payments:create {order-id} {currency=DKK} {--field=*} {--json}';

    protected $description = 'Create a Quickpay payment';

    public function handle(CredentialStore $credentials, QuickpayClientFactory $clients): int
    {
        return $this->withQuickpay($credentials, $clients, function (QuickpayClient $client, string $apiKey): int {
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
            $response = $client->post('/payments', data: $body);

            if (! $response->successful()) {
                return $this->responseFailure($response, $apiKey);
            }

            if ($this->option('json')) {
                $this->writeOriginalJson($response);

                return self::SUCCESS;
            }

            if (! is_array($response->json) || array_is_list($response->json)) {
                throw new InvalidArgumentException('Quickpay returned an invalid created payment.');
            }

            $this->writePaymentDetails($response->json);

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

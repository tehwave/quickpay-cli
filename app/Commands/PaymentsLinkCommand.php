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

class PaymentsLinkCommand extends Command
{
    use InteractsWithQuickpay;
    use WritesPaymentOutput;

    private const NAMED_OPTIONS = [
        'continue-url' => 'continue_url',
        'cancel-url' => 'cancel_url',
        'callback-url' => 'callback_url',
        'language' => 'language',
        'payment-methods' => 'payment_methods',
    ];

    protected $signature = 'payments:link {id} {amount}
        {--continue-url=}
        {--cancel-url=}
        {--callback-url=}
        {--language=}
        {--payment-methods=}
        {--auto-capture}
        {--field=*}
        {--json}';

    protected $description = 'Create a Quickpay payment link';

    public function handle(CredentialStore $credentials, QuickpayClientFactory $clients): int
    {
        return $this->withQuickpay($credentials, $clients, function (QuickpayClient $client, string $apiKey): int {
            $id = $this->positiveInteger($this->argument('id'), 'id');
            $amount = $this->positiveInteger($this->argument('amount'), 'amount');
            $fields = KeyValueParser::parse($this->fieldOptions());

            foreach (['amount', 'continue_url', 'cancel_url', 'callback_url', 'language', 'payment_methods', 'auto_capture'] as $reserved) {
                unset($fields[$reserved]);
            }

            $body = [...$fields, 'amount' => $amount];

            foreach (self::NAMED_OPTIONS as $option => $key) {
                $value = $this->option($option);

                if ($value !== null) {
                    $body[$key] = (string) $value;
                }
            }

            if ($this->option('auto-capture')) {
                $body['auto_capture'] = true;
            }

            $response = $client->put("/payments/{$id}/link", data: $body);

            if (! $response->successful()) {
                return $this->responseFailure($response, $apiKey);
            }

            if ($this->option('json')) {
                $this->writeOriginalJson($response);

                return self::SUCCESS;
            }

            $url = is_array($response->json) ? ($response->json['url'] ?? null) : null;

            if (! is_string($url) || $url === '') {
                throw new InvalidArgumentException('Quickpay did not return a payment link URL.');
            }

            $this->info("Payment link: {$url}");

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

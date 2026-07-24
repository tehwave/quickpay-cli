<?php

namespace App\Commands;

use App\Commands\Concerns\InteractsWithQuickpay;
use App\Commands\Concerns\WritesPaymentOutput;
use App\Credentials\CredentialStore;
use App\Quickpay\QuickpayClient;
use App\Quickpay\QuickpayClientFactory;
use App\Support\ResponseBodySanitizer;
use App\Support\StdinTerminalDetector;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;

abstract class PaymentMutationCommand extends Command
{
    use InteractsWithQuickpay;
    use WritesPaymentOutput;

    public function handle(
        CredentialStore $credentials,
        QuickpayClientFactory $clients,
        StdinTerminalDetector $stdin,
    ): int {
        return $this->withQuickpay($credentials, $clients, function (QuickpayClient $client, string $apiKey) use ($stdin): int {
            $id = $this->positiveInteger($this->argument('id'), 'id');
            $body = $this->mutationBody();
            $headers = $this->callbackHeaders();
            $query = $this->option('synchronized') ? ['synchronized' => true] : [];
            $paymentResponse = $client->get("/payments/{$id}");

            if (! $paymentResponse->successful()) {
                return $this->responseFailure($paymentResponse, $apiKey);
            }

            if (! is_array($paymentResponse->json) || array_is_list($paymentResponse->json)) {
                throw new InvalidArgumentException('Quickpay returned invalid payment context.');
            }

            $this->writeMutationSummary(
                $paymentResponse->json,
                $body['amount'] ?? null,
                $apiKey,
            );

            if (! $this->option('yes')) {
                if (! $this->input->isInteractive() || ! $stdin->isTty()) {
                    return $this->failure('Non-interactive payment mutations require --yes.');
                }

                if (! $this->confirmMutation()) {
                    $this->writeSafetyLine('Cancelled.');

                    return self::SUCCESS;
                }
            }

            $response = $client->post("/payments/{$id}/{$this->operation()}", $query, $body, $headers);

            if (! $response->successful()) {
                return $this->responseFailure($response, $apiKey);
            }

            $safeJson = ResponseBodySanitizer::json($response->rawBody, $apiKey);

            if ($this->option('json')) {
                $this->getOutput()->write($safeJson);

                return self::SUCCESS;
            }

            $payment = json_decode($safeJson, true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($payment) || array_is_list($payment)) {
                throw new InvalidArgumentException('Quickpay returned an invalid payment mutation response.');
            }

            $this->writePaymentDetails($payment, $apiKey);

            return self::SUCCESS;
        });
    }

    abstract protected function operation(): string;

    /** @return array<string, int|float> */
    abstract protected function mutationBody(): array;

    /**
     * @param  array<string, mixed>  $payment
     */
    private function writeMutationSummary(array $payment, mixed $amount, string $apiKey): void
    {
        $fields = [
            'Payment ID' => $payment['id'] ?? null,
            'Order ID' => $payment['order_id'] ?? null,
            'Currency' => $payment['currency'] ?? null,
            'State' => $payment['state'] ?? null,
            'Accepted' => $payment['accepted'] ?? null,
            'Balance' => $payment['balance'] ?? null,
            'Operation' => $this->operation(),
        ];

        if ($amount !== null) {
            $fields['Amount'] = $amount;
        }

        foreach ($fields as $label => $value) {
            $rendered = $this->paymentValue($value, $apiKey);
            $this->writeSafetyLine("{$label}: {$rendered}");
        }
    }

    /** @return array<string, string> */
    private function callbackHeaders(): array
    {
        $callback = $this->option('callback-url');

        if ($callback === null) {
            return [];
        }

        if (filter_var($callback, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($callback, PHP_URL_SCHEME)), ['http', 'https'], true)
            || preg_match('/[\x00-\x1F\x7F]/', $callback) === 1) {
            throw new InvalidArgumentException('callback-url must be a valid HTTP or HTTPS URL.');
        }

        return ['QuickPay-Callback-Url' => $callback];
    }

    private function writeSafetyLine(string $message): void
    {
        $output = $this->option('json')
            ? $this->getOutput()->getErrorStyle()
            : $this->getOutput();

        $output->writeln($message, OutputInterface::OUTPUT_RAW);
    }

    private function confirmMutation(): bool
    {
        if (! $this->option('json')) {
            return $this->confirm('Continue?');
        }

        $output = $this->output;
        $this->output = new OutputStyle($this->input, $output->getErrorStyle());

        try {
            return $this->confirm('Continue?');
        } finally {
            $this->output = $output;
        }
    }
}

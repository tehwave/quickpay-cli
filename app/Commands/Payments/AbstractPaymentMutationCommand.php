<?php

namespace App\Commands\Payments;

use App\Console\AuthenticatedCommand;
use App\Console\Confirmation\MutationConfirmation;
use App\Console\Input\IntegerInput;
use App\Console\Output\PaymentPresenter;
use App\Console\Output\ResponseBodySanitizer;
use App\Quickpay\AuthenticatedQuickpay;
use App\Quickpay\AuthenticatedQuickpayFactory;
use App\Quickpay\Payments\PaymentMutation;
use Illuminate\Console\OutputStyle;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Executes payment mutations behind a shared inspect-and-confirm workflow.
 *
 * Fetching current payment context before confirmation gives the operator the
 * identity, currency, state, and balance needed to verify a destructive action.
 */
abstract class AbstractPaymentMutationCommand extends AuthenticatedCommand
{
    public function handle(
        AuthenticatedQuickpayFactory $quickpay,
        MutationConfirmation $confirmation,
        PaymentPresenter $presenter,
    ): int {
        return $this->withQuickpay($quickpay, function (AuthenticatedQuickpay $authenticated) use ($confirmation, $presenter): int {
            $apiKey = $authenticated->apiKey->value();
            $id = IntegerInput::positive($this->argument('id'), 'id');
            $body = $this->mutationBody();
            $headers = $this->callbackHeaders();
            $query = $this->option('synchronized') ? ['synchronized' => true] : [];
            $result = (new PaymentMutation($authenticated->payments))->execute(
                id: $id,
                operation: $this->operation(),
                body: $body,
                query: $query,
                headers: $headers,
                confirm: function (array $payment) use ($apiKey, $body, $confirmation, $presenter): bool {
                    $this->writeMutationSummary(
                        $payment,
                        $body['amount'] ?? null,
                        $apiKey,
                        $presenter,
                    );

                    return $confirmation->approve(
                        preapproved: (bool) $this->option('yes'),
                        interactive: $this->input->isInteractive(),
                        ask: fn (): bool => $this->confirmMutation(),
                        nonInteractiveMessage: 'Non-interactive payment mutations require --yes.',
                    );
                },
            );

            if ($result === null) {
                $this->writeSafetyLine('Cancelled.');

                return self::SUCCESS;
            }

            $safeJson = ResponseBodySanitizer::json($result->response->rawBody, $apiKey);

            if ($this->option('json')) {
                // The response is validated before stdout is touched so a
                // failed mutation can never emit partial "successful" JSON.
                $this->getOutput()->write($safeJson);

                return self::SUCCESS;
            }

            $this->table(['Field', 'Value'], $presenter->details($result->data, $apiKey));

            return self::SUCCESS;
        });
    }

    abstract protected function operation(): string;

    /** @return array<string, int|float> */
    abstract protected function mutationBody(): array;

    /**
     * @param  array<string, mixed>  $payment
     */
    private function writeMutationSummary(
        array $payment,
        mixed $amount,
        string $apiKey,
        PaymentPresenter $presenter,
    ): void {
        $fields = [
            'Payment ID' => $payment['id'] ?? null,
            'Order ID' => $payment['order_id'] ?? null,
            'Currency' => $payment['currency'] ?? null,
            'State' => $payment['state'] ?? null,
            'Accepted' => $payment['accepted'] ?? null,
            'Balance (minor units)' => $payment['balance'] ?? null,
            'Operation' => $this->operation(),
        ];

        if ($amount !== null) {
            $fields['Amount (minor units)'] = $amount;
        }

        foreach ($fields as $label => $value) {
            $rendered = $presenter->value($value, $apiKey);
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
        // JSON mode reserves stdout for the response document. Human safety
        // context and prompts therefore move to stderr.
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

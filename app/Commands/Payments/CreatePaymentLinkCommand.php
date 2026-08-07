<?php

namespace App\Commands\Payments;

use App\Console\AuthenticatedCommand;
use App\Console\Input\IntegerInput;
use App\Console\Input\KeyValueParser;
use App\Console\Output\PaymentPresenter;
use App\Console\Output\ResponseBodySanitizer;
use App\Quickpay\AuthenticatedQuickpay;
use App\Quickpay\AuthenticatedQuickpayFactory;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;

class CreatePaymentLinkCommand extends AuthenticatedCommand
{
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
        {--callback-url= : Callback URL for Quickpay servers (not localhost)}
        {--language=}
        {--payment-methods=}
        {--auto-capture}
        {--field=*}
        {--json}';

    protected $description = 'Create a Quickpay payment link';

    public function handle(AuthenticatedQuickpayFactory $quickpay, PaymentPresenter $presenter): int
    {
        return $this->withQuickpay($quickpay, function (AuthenticatedQuickpay $authenticated) use ($presenter): int {
            $apiKey = $authenticated->apiKey->value();
            $id = IntegerInput::positive($this->argument('id'), 'id');
            $amount = IntegerInput::positive($this->argument('amount'), 'amount');
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

            $result = $authenticated->payments->link($id, $body);
            $link = $result->data;
            $url = $link['url'] ?? null;

            if (! is_string($url) || $url === '') {
                throw new InvalidArgumentException('Quickpay did not return a payment link URL.');
            }

            if ($this->option('json')) {
                $this->getOutput()->write(ResponseBodySanitizer::json($result->response->rawBody, $apiKey));

                return self::SUCCESS;
            }

            $this->getOutput()->writeln(
                'Payment link: '.$presenter->value($url, $apiKey),
                OutputInterface::OUTPUT_RAW,
            );

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

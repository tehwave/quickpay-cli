<?php

namespace App\Commands\Payments;

use App\Console\AuthenticatedCommand;
use App\Console\Input\IntegerInput;
use App\Console\Output\JsonOutput;
use App\Console\Output\PaymentPresenter;
use App\Console\Output\ResponseBodySanitizer;
use App\Quickpay\AuthenticatedQuickpay;
use App\Quickpay\AuthenticatedQuickpayFactory;
use App\Quickpay\Payments\PaymentPaginator;
use InvalidArgumentException;

class ListPaymentsCommand extends AuthenticatedCommand
{
    protected $signature = 'payments:list
        {--accepted}
        {--state=}
        {--order-id=}
        {--created-after=}
        {--created-before=}
        {--page-size=20}
        {--all}
        {--max-pages=100}
        {--json}';

    protected $description = 'List Quickpay payments';

    public function handle(AuthenticatedQuickpayFactory $quickpay, PaymentPresenter $presenter): int
    {
        return $this->withQuickpay($quickpay, function (AuthenticatedQuickpay $authenticated) use ($presenter): int {
            $apiKey = $authenticated->apiKey->value();
            $pageSize = IntegerInput::positive($this->option('page-size'), 'page-size');
            $maxPages = IntegerInput::positive($this->option('max-pages'), 'max-pages');

            if ($pageSize > 100) {
                throw new InvalidArgumentException('page-size must be an integer from 1 through 100.');
            }

            $query = $this->query($pageSize);

            if (! $this->option('all')) {
                $page = $authenticated->payments->firstPage($query);

                if ($this->option('json')) {
                    $this->getOutput()->write(ResponseBodySanitizer::json($page->response->rawBody, $apiKey));

                    return self::SUCCESS;
                }

                $this->writePayments($page->payments, $apiKey, $presenter);

                return self::SUCCESS;
            }

            $payments = (new PaymentPaginator($authenticated->payments))->all($query, $maxPages);

            if ($this->option('json')) {
                $this->getOutput()->write(JsonOutput::value($payments, $apiKey));

                return self::SUCCESS;
            }

            $this->writePayments($payments, $apiKey, $presenter);

            return self::SUCCESS;
        });
    }

    /** @param array<int, mixed> $payments */
    private function writePayments(array $payments, string $apiKey, PaymentPresenter $presenter): void
    {
        if ($payments === []) {
            $this->line('No payments found.');

            return;
        }

        $this->table(
            ['ID', 'Order ID', 'Accepted', 'State', 'Currency', 'Balance / amount', 'Created'],
            $presenter->payments($payments, $apiKey),
        );
    }

    /** @return array<string, mixed> */
    private function query(int $pageSize): array
    {
        $query = [];

        if ($this->option('accepted')) {
            $query['accepted'] = 'true';
        }

        foreach (['state' => 'state', 'order-id' => 'order_id'] as $option => $parameter) {
            $value = $this->option($option);

            if (is_string($value) && $value !== '') {
                $query[$parameter] = $value;
            }
        }

        $after = $this->option('created-after');
        $before = $this->option('created-before');

        if ((is_string($after) && $after !== '') || (is_string($before) && $before !== '')) {
            $query['timestamp'] = 'created_at';
        }

        if (is_string($after) && $after !== '') {
            $query['min_time'] = $after;
        }

        if (is_string($before) && $before !== '') {
            $query['max_time'] = $before;
        }

        $query['page_size'] = $pageSize;

        return $query;
    }
}

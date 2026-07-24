<?php

namespace App\Commands;

use App\Commands\Concerns\InteractsWithQuickpay;
use App\Commands\Concerns\WritesPaymentOutput;
use App\Credentials\CredentialStore;
use App\Quickpay\QuickpayClient;
use App\Quickpay\QuickpayClientFactory;
use App\Quickpay\QuickpayResponse;
use App\Support\LinkHeaderParser;
use App\Support\PaginationTargetCanonicalizer;
use Illuminate\Console\Command;
use InvalidArgumentException;

class PaymentsListCommand extends Command
{
    use InteractsWithQuickpay;
    use WritesPaymentOutput;

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

    public function handle(CredentialStore $credentials, QuickpayClientFactory $clients): int
    {
        return $this->withQuickpay($credentials, $clients, function (QuickpayClient $client, string $apiKey): int {
            $pageSize = $this->positiveInteger($this->option('page-size'), 'page-size');
            $maxPages = $this->positiveInteger($this->option('max-pages'), 'max-pages');

            if ($pageSize > 100) {
                throw new InvalidArgumentException('page-size must be an integer from 1 through 100.');
            }

            $query = $this->query($pageSize);
            $response = $client->get('/payments', $query);

            if (! $response->successful()) {
                return $this->responseFailure($response, $apiKey);
            }

            if (! $this->option('all')) {
                if ($this->option('json')) {
                    $this->writeOriginalJson($response);

                    return self::SUCCESS;
                }

                $payments = $this->paymentList($response);
                $this->writePaymentsTable($payments);

                return self::SUCCESS;
            }

            $payments = $this->paymentList($response);
            $pageCount = 1;
            $seen = [PaginationTargetCanonicalizer::fromQuery('/payments', $query) => true];
            $next = LinkHeaderParser::next($response->header('Link'));

            while ($next !== null) {
                if ($pageCount >= $maxPages) {
                    throw new InvalidArgumentException("Pagination exceeded the configured maximum of {$maxPages} pages.");
                }

                $canonicalNext = PaginationTargetCanonicalizer::canonical($next);

                if (isset($seen[$canonicalNext])) {
                    throw new InvalidArgumentException('Quickpay returned a pagination cycle.');
                }

                $seen[$canonicalNext] = true;
                $response = $client->get($next);
                $pageCount++;

                if (! $response->successful()) {
                    return $this->responseFailure($response, $apiKey);
                }

                array_push($payments, ...$this->paymentList($response));
                $next = LinkHeaderParser::next($response->header('Link'));
            }

            if ($this->option('json')) {
                $this->getOutput()->write(json_encode($payments, JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $this->writePaymentsTable($payments);

            return self::SUCCESS;
        });
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

    /** @return array<int, mixed> */
    private function paymentList(QuickpayResponse $response): array
    {
        if (! is_array($response->json) || ! array_is_list($response->json)) {
            throw new InvalidArgumentException('Quickpay returned an invalid payment list.');
        }

        return $response->json;
    }
}

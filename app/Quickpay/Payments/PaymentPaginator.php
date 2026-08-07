<?php

namespace App\Quickpay\Payments;

use App\Quickpay\Pagination\LinkHeaderParser;
use App\Quickpay\Pagination\PaginationTargetCanonicalizer;
use InvalidArgumentException;

final readonly class PaymentPaginator
{
    public function __construct(private PaymentApi $payments) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, mixed>
     */
    public function all(array $query, int $maxPages): array
    {
        $page = $this->payments->firstPage($query);
        $payments = $page->payments;
        $pageCount = 1;
        $seen = [PaginationTargetCanonicalizer::fromQuery('/payments', $query) => true];
        $next = LinkHeaderParser::next($page->response->header('Link'));

        while ($next !== null) {
            if ($pageCount >= $maxPages) {
                throw new InvalidArgumentException("Pagination exceeded the configured maximum of {$maxPages} pages.");
            }

            $canonicalNext = PaginationTargetCanonicalizer::canonical($next);

            if (isset($seen[$canonicalNext])) {
                throw new InvalidArgumentException('Quickpay returned a pagination cycle.');
            }

            $seen[$canonicalNext] = true;
            $page = $this->payments->nextPage($next);
            $pageCount++;
            array_push($payments, ...$page->payments);
            $next = LinkHeaderParser::next($page->response->header('Link'));
        }

        return $payments;
    }
}

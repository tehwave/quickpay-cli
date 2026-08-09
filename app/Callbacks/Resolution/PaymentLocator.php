<?php

namespace App\Callbacks\Resolution;

use App\Callbacks\Watching\CallbackPollingException;
use App\Quickpay\Pagination\LinkHeaderParser;
use App\Quickpay\Pagination\PaginationTargetCanonicalizer;
use App\Quickpay\QuickpayClient;
use App\Quickpay\QuickpayResponse;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Resolves the command's payment selector to one current payment resource.
 *
 * Order lookup requests at most two rows: one proves uniqueness and two prove
 * ambiguity without downloading an unbounded payment collection. A matching
 * list row is always followed by GET /payments/{id}, because callbacks contain
 * the complete current resource rather than the abbreviated list row.
 */
final readonly class PaymentLocator
{
    public function __construct(private QuickpayClient $quickpay) {}

    /** @return array<int, QuickpayResponse> */
    public function changedBetween(DateTimeImmutable $minimum, DateTimeImmutable $maximum): array
    {
        $utc = new DateTimeZone('UTC');
        $query = [
            'timestamp' => 'updated_at',
            'min_time' => $minimum->setTimezone($utc)->format('Y-m-d H:i:s O'),
            'max_time' => $maximum->setTimezone($utc)->format('Y-m-d H:i:s O'),
            'operations_size' => 0,
            'page_size' => 100,
        ];
        $response = $this->changedPage('/payments', $query);
        $pageCount = 1;
        $seen = [PaginationTargetCanonicalizer::fromQuery('/payments', $query) => true];

        $ids = [];

        while (true) {
            foreach ($response->json as $payment) {
                $id = is_array($payment) && ! array_is_list($payment) ? ($payment['id'] ?? null) : null;

                if ((! is_int($id) && ! is_string($id)) || (string) $id === '') {
                    throw new UnexpectedValueException('Quickpay returned a changed-payment row without a valid payment ID.');
                }

                $ids[(string) $id] = true;
            }

            $next = LinkHeaderParser::next($response->header('Link'));

            if ($next === null) {
                break;
            }

            if ($pageCount >= 100) {
                throw new InvalidArgumentException('Pagination exceeded the configured maximum of 100 pages.');
            }

            $canonicalNext = PaginationTargetCanonicalizer::canonical($next);

            if (isset($seen[$canonicalNext])) {
                throw new InvalidArgumentException('Quickpay returned a pagination cycle.');
            }

            $seen[$canonicalNext] = true;
            $response = $this->changedPage($next);
            $pageCount++;
        }

        return array_map($this->byId(...), array_keys($ids));
    }

    /** @param array<string, mixed> $query */
    private function changedPage(string $path, array $query = []): QuickpayResponse
    {
        $response = $this->quickpay->get($path, $query);

        if (! $response->successful()) {
            throw $this->pollingFailure($response);
        }

        if (! is_array($response->json) || ! array_is_list($response->json)) {
            throw new UnexpectedValueException('Quickpay returned a malformed changed-payment response.');
        }

        return $response;
    }

    public function byId(string $paymentId): QuickpayResponse
    {
        $response = $this->quickpay->get('/payments/'.rawurlencode($paymentId));

        if (! $response->successful()) {
            throw $this->pollingFailure($response);
        }

        if (! is_array($response->json)
            || array_is_list($response->json)
            || ! isset($response->json['id'])
            || (string) $response->json['id'] !== $paymentId) {
            throw new UnexpectedValueException('Quickpay returned a malformed or mismatched payment resource.');
        }

        return $response;
    }

    public function byOrderId(string $orderId): ?QuickpayResponse
    {
        $response = $this->quickpay->get('/payments', [
            'order_id' => $orderId,
            'page_size' => 2,
        ]);

        if (! $response->successful()) {
            throw $this->pollingFailure($response);
        }

        if (! is_array($response->json) || ! array_is_list($response->json)) {
            throw new UnexpectedValueException('Quickpay returned a malformed payment lookup response.');
        }

        $matches = array_values(array_filter(
            $response->json,
            fn (mixed $payment): bool => is_array($payment)
                && isset($payment['order_id'])
                && (string) $payment['order_id'] === $orderId,
        ));

        if ($matches === []) {
            return null;
        }

        if (count($matches) > 1) {
            $ids = array_map(
                fn (array $payment): string => isset($payment['id']) && is_scalar($payment['id'])
                    ? (string) $payment['id']
                    : 'unknown',
                $matches,
            );

            throw new UnexpectedValueException('Order ID matched multiple payments: '.implode(', ', $ids).'. Use a payment ID instead.');
        }

        $paymentId = $matches[0]['id'] ?? null;
        if ((! is_int($paymentId) && ! is_string($paymentId)) || (string) $paymentId === '') {
            throw new UnexpectedValueException('Quickpay returned an order match without a valid payment ID.');
        }

        return $this->byId((string) $paymentId);
    }

    private function pollingFailure(QuickpayResponse $response): CallbackPollingException
    {
        $retryAfter = $response->header('Retry-After');
        $numericRetryAfter = is_string($retryAfter) && preg_match('/\A[0-9]+\z/D', $retryAfter) === 1
            ? (int) $retryAfter
            : null;

        return new CallbackPollingException(
            message: $response->errorSummary(),
            status: $response->status,
            retryable: $response->status === 429 || $response->status >= 500,
            retryAfter: $response->status === 429 ? $numericRetryAfter : null,
        );
    }
}

<?php

namespace App\Quickpay\Payments;

use App\Quickpay\QuickpayClient;
use App\Quickpay\QuickpayResponse;
use InvalidArgumentException;
use UnexpectedValueException;

final readonly class PaymentApi
{
    public function __construct(private QuickpayClient $client) {}

    /** @param array<string, mixed> $body */
    public function create(array $body): PaymentResult
    {
        return $this->object(
            $this->client->post('/payments', data: $body),
            'Quickpay returned an invalid created payment.',
        );
    }

    /** @param array<string, mixed> $query */
    public function get(int $id, array $query = [], string $invalid = 'Quickpay returned an invalid payment.'): PaymentResult
    {
        return $this->object($this->client->get("/payments/{$id}", $query), $invalid);
    }

    /** @param array<string, mixed> $body */
    public function link(int $id, array $body): PaymentResult
    {
        return $this->object(
            $this->client->put("/payments/{$id}/link", data: $body),
            'Quickpay did not return a payment link URL.',
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, int|float>  $body
     * @param  array<string, string>  $headers
     */
    public function mutate(
        int $id,
        string $operation,
        array $query,
        array $body,
        array $headers,
    ): PaymentResult {
        $response = $this->client->post("/payments/{$id}/{$operation}", $query, $body, $headers);
        $this->successful($response);

        if (! json_validate($response->rawBody)) {
            throw new InvalidArgumentException('Quickpay returned a successful response without valid JSON.');
        }

        return new PaymentResult(
            $response,
            PaymentResponse::object($response, 'Quickpay returned an invalid payment mutation response.'),
        );
    }

    /** @param array<string, mixed> $query */
    public function firstPage(array $query): PaymentPage
    {
        return $this->page($this->client->get('/payments', $query));
    }

    public function nextPage(string $target): PaymentPage
    {
        return $this->page($this->client->get($target));
    }

    private function object(QuickpayResponse $response, string $invalid): PaymentResult
    {
        $this->successful($response);

        return new PaymentResult($response, PaymentResponse::object($response, $invalid));
    }

    private function page(QuickpayResponse $response): PaymentPage
    {
        $this->successful($response);

        return new PaymentPage(
            $response,
            PaymentResponse::list($response, 'Quickpay returned an invalid payment list.'),
        );
    }

    private function successful(QuickpayResponse $response): void
    {
        if (! $response->successful()) {
            throw new UnexpectedValueException($response->errorSummary());
        }
    }
}

<?php

namespace App\Commands\Concerns;

use App\Quickpay\QuickpayResponse;
use App\Support\ResponseBodySanitizer;
use InvalidArgumentException;
use JsonException;
use stdClass;

trait WritesPaymentOutput
{
    protected function writeOriginalJson(QuickpayResponse $response, string $apiKey): void
    {
        $this->getOutput()->write(ResponseBodySanitizer::json($response->rawBody, $apiKey));
    }

    protected function writeJsonValue(mixed $value, string $apiKey): void
    {
        try {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Quickpay returned JSON that could not be rendered safely.');
        }

        $this->getOutput()->write(ResponseBodySanitizer::json($json, $apiKey));
    }

    /** @return array<int, mixed> */
    protected function jsonList(QuickpayResponse $response, string $message): array
    {
        [$decoded, $associative] = $this->decodeJson($response, $message);

        if (! is_array($decoded) || ! is_array($associative)) {
            throw new InvalidArgumentException($message);
        }

        return $associative;
    }

    /** @return array<string, mixed> */
    protected function jsonObject(QuickpayResponse $response, string $message): array
    {
        [$decoded, $associative] = $this->decodeJson($response, $message);

        if (! $decoded instanceof stdClass || ! is_array($associative)) {
            throw new InvalidArgumentException($message);
        }

        return $associative;
    }

    /** @param array<string, mixed> $payment */
    protected function writePaymentDetails(array $payment, string $apiKey): void
    {
        $fields = [
            'ID' => 'id',
            'Order ID' => 'order_id',
            'Currency' => 'currency',
            'State' => 'state',
            'Accepted' => 'accepted',
            'Balance' => 'balance',
            'Test mode' => 'test_mode',
            'Created' => 'created_at',
            'Updated' => 'updated_at',
        ];
        $rows = [];

        foreach ($fields as $label => $key) {
            $rows[] = [$label, $this->paymentValue($payment[$key] ?? null, $apiKey)];
        }

        $this->table(['Field', 'Value'], $rows);
    }

    /** @param array<int, mixed> $payments */
    protected function writePaymentsTable(array $payments, string $apiKey): void
    {
        if ($payments === []) {
            $this->line('No payments found.');

            return;
        }

        $rows = array_map(fn (mixed $payment): array => is_array($payment) ? [
            $this->paymentValue($payment['id'] ?? null, $apiKey),
            $this->paymentValue($payment['order_id'] ?? null, $apiKey),
            $this->paymentValue($payment['accepted'] ?? null, $apiKey),
            $this->paymentValue($payment['state'] ?? null, $apiKey),
            $this->paymentValue($payment['currency'] ?? null, $apiKey),
            $this->paymentValue($payment['balance'] ?? $payment['amount'] ?? null, $apiKey),
            $this->paymentValue($payment['created_at'] ?? null, $apiKey),
        ] : ['-', '-', '-', '-', '-', '-', '-'], $payments);

        $this->table(
            ['ID', 'Order ID', 'Accepted', 'State', 'Currency', 'Balance / amount', 'Created'],
            $rows,
        );
    }

    /** @param array<int, mixed> $operations */
    protected function writeOperationsTable(array $operations, string $apiKey): void
    {
        if ($operations === []) {
            return;
        }

        $this->newLine();
        $this->line('Operations');
        $rows = array_map(fn (mixed $operation): array => is_array($operation) ? [
            $this->paymentValue($operation['id'] ?? null, $apiKey),
            $this->paymentValue($operation['type'] ?? null, $apiKey),
            $this->paymentValue($operation['amount'] ?? null, $apiKey),
            $this->paymentValue($operation['pending'] ?? null, $apiKey),
            $this->statusValue($operation, 'qp', $apiKey),
            $this->statusValue($operation, 'aq', $apiKey),
            $this->paymentValue($operation['created_at'] ?? null, $apiKey),
        ] : ['-', '-', '-', '-', '-', '-', '-'], $operations);

        $this->table(['ID', 'Type', 'Amount', 'Pending', 'Quickpay status', 'Acquirer status', 'Created'], $rows);
    }

    protected function paymentValue(mixed $value, string $apiKey): string
    {
        if (is_bool($value)) {
            $rendered = $value ? 'yes' : 'no';
        } elseif ($value === null) {
            $rendered = '-';
        } elseif (is_scalar($value)) {
            $rendered = (string) $value;
        } else {
            $rendered = json_encode($value, JSON_UNESCAPED_SLASHES) ?: '-';
        }

        return $this->safeTerminalText($rendered, $apiKey);
    }

    protected function safeTerminalText(string $value, string $apiKey): string
    {
        return ResponseBodySanitizer::terminalLine($value, $apiKey);
    }

    /** @param array<string, mixed> $operation */
    private function statusValue(array $operation, string $prefix, string $apiKey): string
    {
        $parts = array_filter([
            isset($operation[$prefix.'_status_code']) ? (string) $operation[$prefix.'_status_code'] : null,
            isset($operation[$prefix.'_status_msg']) ? (string) $operation[$prefix.'_status_msg'] : null,
        ], fn (?string $part): bool => $part !== null && $part !== '');

        return $this->safeTerminalText($parts === [] ? '-' : implode(' ', $parts), $apiKey);
    }

    /** @return array{0: mixed, 1: mixed} */
    private function decodeJson(QuickpayResponse $response, string $message): array
    {
        try {
            return [
                json_decode($response->rawBody, flags: JSON_THROW_ON_ERROR),
                json_decode($response->rawBody, true, flags: JSON_THROW_ON_ERROR),
            ];
        } catch (JsonException) {
            throw new InvalidArgumentException($message);
        }
    }
}

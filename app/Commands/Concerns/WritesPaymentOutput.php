<?php

namespace App\Commands\Concerns;

use App\Quickpay\QuickpayResponse;

trait WritesPaymentOutput
{
    protected function writeOriginalJson(QuickpayResponse $response): void
    {
        $this->getOutput()->write($response->rawBody);
    }

    /** @param array<string, mixed> $payment */
    protected function writePaymentDetails(array $payment): void
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
            $rows[] = [$label, $this->paymentValue($payment[$key] ?? null)];
        }

        $this->table(['Field', 'Value'], $rows);
    }

    /** @param array<int, mixed> $payments */
    protected function writePaymentsTable(array $payments): void
    {
        if ($payments === []) {
            $this->line('No payments found.');

            return;
        }

        $rows = array_map(fn (mixed $payment): array => is_array($payment) ? [
            $this->paymentValue($payment['id'] ?? null),
            $this->paymentValue($payment['order_id'] ?? null),
            $this->paymentValue($payment['accepted'] ?? null),
            $this->paymentValue($payment['state'] ?? null),
            $this->paymentValue($payment['currency'] ?? null),
            $this->paymentValue($payment['balance'] ?? $payment['amount'] ?? null),
            $this->paymentValue($payment['created_at'] ?? null),
        ] : ['-', '-', '-', '-', '-', '-', '-'], $payments);

        $this->table(
            ['ID', 'Order ID', 'Accepted', 'State', 'Currency', 'Balance / amount', 'Created'],
            $rows,
        );
    }

    /** @param array<int, mixed> $operations */
    protected function writeOperationsTable(array $operations): void
    {
        if ($operations === []) {
            return;
        }

        $this->newLine();
        $this->line('Operations');
        $rows = array_map(fn (mixed $operation): array => is_array($operation) ? [
            $this->paymentValue($operation['id'] ?? null),
            $this->paymentValue($operation['type'] ?? null),
            $this->paymentValue($operation['amount'] ?? null),
            $this->paymentValue($operation['pending'] ?? null),
            $this->statusValue($operation, 'qp'),
            $this->statusValue($operation, 'aq'),
            $this->paymentValue($operation['created_at'] ?? null),
        ] : ['-', '-', '-', '-', '-', '-', '-'], $operations);

        $this->table(['ID', 'Type', 'Amount', 'Pending', 'Quickpay status', 'Acquirer status', 'Created'], $rows);
    }

    protected function paymentValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if ($value === null) {
            return '-';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '-';
    }

    /** @param array<string, mixed> $operation */
    private function statusValue(array $operation, string $prefix): string
    {
        $parts = array_filter([
            isset($operation[$prefix.'_status_code']) ? (string) $operation[$prefix.'_status_code'] : null,
            isset($operation[$prefix.'_status_msg']) ? (string) $operation[$prefix.'_status_msg'] : null,
        ], fn (?string $part): bool => $part !== null && $part !== '');

        return $parts === [] ? '-' : implode(' ', $parts);
    }
}

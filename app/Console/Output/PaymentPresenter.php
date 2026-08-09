<?php

namespace App\Console\Output;

final class PaymentPresenter
{
    /**
     * @param  array<string, mixed>  $payment
     * @return array<int, array{string, string}>
     */
    public function details(array $payment, string $apiKey): array
    {
        $fields = [
            'ID' => 'id',
            'Order ID' => 'order_id',
            'Currency' => 'currency',
            'State' => 'state',
            'Accepted' => 'accepted',
            'Balance (minor units)' => 'balance',
            'Test mode' => 'test_mode',
            'Created' => 'created_at',
            'Updated' => 'updated_at',
        ];

        return array_map(
            fn (string $label, string $key): array => [$label, $this->value($payment[$key] ?? null, $apiKey)],
            array_keys($fields),
            array_values($fields),
        );
    }

    /**
     * @param  array<int, mixed>  $payments
     * @return array<int, array<int, string>>
     */
    public function payments(array $payments, string $apiKey): array
    {
        return array_map(fn (mixed $payment): array => is_array($payment) ? [
            $this->value($payment['id'] ?? null, $apiKey),
            $this->value($payment['order_id'] ?? null, $apiKey),
            $this->value($payment['accepted'] ?? null, $apiKey),
            $this->value($payment['state'] ?? null, $apiKey),
            $this->value($payment['currency'] ?? null, $apiKey),
            $this->value($payment['balance'] ?? $payment['amount'] ?? null, $apiKey),
            $this->value($payment['created_at'] ?? null, $apiKey),
        ] : ['-', '-', '-', '-', '-', '-', '-'], $payments);
    }

    /**
     * @param  array<int, mixed>  $operations
     * @return array<int, array<int, string>>
     */
    public function operations(array $operations, string $apiKey): array
    {
        return array_map(fn (mixed $operation): array => is_array($operation) ? [
            $this->value($operation['id'] ?? null, $apiKey),
            $this->value($operation['type'] ?? null, $apiKey),
            $this->value($operation['amount'] ?? null, $apiKey),
            $this->value($operation['pending'] ?? null, $apiKey),
            $this->status($operation, 'qp', $apiKey),
            $this->status($operation, 'aq', $apiKey),
            $this->callback($operation, $apiKey),
            $this->value($operation['created_at'] ?? null, $apiKey),
        ] : ['-', '-', '-', '-', '-', '-', '-', '-'], $operations);
    }

    public function value(mixed $value, string $apiKey): string
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

        return ResponseBodySanitizer::terminalLine($rendered, $apiKey);
    }

    /** @param array<string, mixed> $operation */
    private function status(array $operation, string $prefix, string $apiKey): string
    {
        $parts = array_filter([
            isset($operation[$prefix.'_status_code']) ? (string) $operation[$prefix.'_status_code'] : null,
            isset($operation[$prefix.'_status_msg']) ? (string) $operation[$prefix.'_status_msg'] : null,
        ], fn (?string $part): bool => $part !== null && $part !== '');

        return ResponseBodySanitizer::terminalLine($parts === [] ? '-' : implode(' ', $parts), $apiKey);
    }

    /** @param array<string, mixed> $operation */
    private function callback(array $operation, string $apiKey): string
    {
        $success = $operation['callback_success'] ?? null;
        $parts = array_filter([
            is_bool($success) ? ($success ? 'yes' : 'no') : null,
            isset($operation['callback_response_code']) && is_scalar($operation['callback_response_code'])
                ? (string) $operation['callback_response_code']
                : null,
            isset($operation['callback_at']) && is_scalar($operation['callback_at'])
                ? (string) $operation['callback_at']
                : null,
        ], fn (?string $part): bool => $part !== null && $part !== '');

        return ResponseBodySanitizer::terminalLine($parts === [] ? '-' : implode(' / ', $parts), $apiKey);
    }
}

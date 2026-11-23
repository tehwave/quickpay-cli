<?php

namespace App\Console\Output;

use App\Callbacks\Watching\CallbackWatchObserver;
use DateTimeImmutable;
use Illuminate\Console\Command;

/**
 * Renders watch progress as terminal lines with the API key redacted.
 */
final readonly class CallbackWatchProgress implements CallbackWatchObserver
{
    public function __construct(
        private Command $command,
        private string $apiKey,
        private string $destination,
    ) {}

    public function watchingAllPayments(DateTimeImmutable $readyAt): void
    {
        $this->command->info('Watching all Quickpay payment callbacks. Press Ctrl-C to stop.');
        $this->command->line(
            "Ready at {$this->safe($readyAt->format(DateTimeImmutable::ATOM))}; "
            ."forwarding to {$this->safe($this->destination)}.",
        );
    }

    public function waitingForPayment(string $orderId): void
    {
        $this->command->line("Waiting for order {$this->safe($orderId)} to create a payment.");
    }

    public function watchingPayment(string $paymentId, int $baselineOperations): void
    {
        $this->command->line("Payment {$this->safe($paymentId)} ready; {$this->safe((string) $baselineOperations)} existing operation(s) baselined.");
    }

    public function paymentFound(string $paymentId, string $orderId): void
    {
        $this->command->line("Order {$this->safe($orderId)} created payment {$this->safe($paymentId)}.");
    }

    public function multipleOperations(int $count): void
    {
        $this->command->warn("Detected {$this->safe((string) $count)} operations in one poll; forwarding one callback per operation with the same latest payment snapshot.");
    }

    public function deliveryRetrying(
        string $paymentId,
        string $operationId,
        int $attempt,
        int $maximumAttempts,
        int $delay,
        ?int $status,
    ): void {
        $deliveryStatus = $status === null ? 'no HTTP response' : "HTTP {$this->safe((string) $status)}";

        $this->command->warn(
            "Callback for {$this->operation($paymentId, $operationId)} failed on attempt {$this->safe((string) $attempt)} of {$this->safe((string) $maximumAttempts)} "
            ."({$deliveryStatus}); retrying in {$this->safe((string) $delay)} second(s) before later operations.",
        );
    }

    public function pollingRetrying(?int $status, int $delay): void
    {
        $this->command->warn("Quickpay polling failed (HTTP {$this->safe((string) ($status ?? '-'))}); retrying in {$this->safe((string) $delay)} second(s).");
    }

    public function delivered(string $paymentId, string $operationId, ?int $status): void
    {
        $this->command->info(
            "Delivered callback for {$this->operation($paymentId, $operationId)} with HTTP {$this->safe((string) ($status ?? '-'))}.",
        );
    }

    private function operation(string $paymentId, string $operationId): string
    {
        return "payment {$this->safe($paymentId)} operation {$this->safe($operationId)}";
    }

    private function safe(string $value): string
    {
        return ResponseBodySanitizer::terminalLine($value, $this->apiKey);
    }
}

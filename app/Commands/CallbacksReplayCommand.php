<?php

namespace App\Commands;

use App\Callbacks\AccountPrivateKeyFetcher;
use App\Callbacks\CallbackEnvelopeFactory;
use App\Callbacks\CallbackForwarder;
use App\Callbacks\PaymentLocator;
use App\Callbacks\PrivateKeyResolver;
use App\Commands\Concerns\InteractsWithCallbackInput;
use App\Commands\Concerns\InteractsWithQuickpay;
use App\Commands\Concerns\WritesPaymentOutput;
use App\Credentials\CredentialStore;
use App\Quickpay\QuickpayClient;
use App\Quickpay\QuickpayClientFactory;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;

/**
 * Replays Quickpay's current payment callback to an explicit destination.
 *
 * This reproduces the callback envelope developers need locally, not historic
 * delivery metadata such as Quickpay source IPs or the resource's old state.
 */
class CallbacksReplayCommand extends Command
{
    use InteractsWithCallbackInput;
    use InteractsWithQuickpay;
    use WritesPaymentOutput;

    protected $signature = 'callbacks:replay
        {payment-id? : Quickpay payment ID}
        {--order-id= : Resolve one payment by its order ID}
        {--to= : HTTP or HTTPS callback destination}
        {--json : Write a machine-readable delivery summary}';

    protected $description = 'Replay a signed Quickpay payment callback';

    public function handle(
        CredentialStore $credentials,
        QuickpayClientFactory $clients,
        Factory $http,
    ): int {
        return $this->withQuickpay(
            $credentials,
            $clients,
            fn (QuickpayClient $quickpay, string $apiKey): int => $this->replay($quickpay, $http, $apiKey),
        );
    }

    private function replay(QuickpayClient $quickpay, Factory $http, string $apiKey): int
    {
        [$paymentId, $orderId] = $this->callbackSelector();
        $target = $this->callbackTarget();
        $locator = new PaymentLocator($quickpay);
        $payment = $paymentId !== null
            ? $locator->byId($paymentId)
            : $locator->byOrderId($orderId ?? '');

        if ($payment === null) {
            throw new InvalidArgumentException("No payment found for order ID {$orderId}.");
        }

        $privateKey = (new PrivateKeyResolver(
            fn (): string => (new AccountPrivateKeyFetcher)->fetch($quickpay),
        ))->resolve();
        $envelope = (new CallbackEnvelopeFactory)->make($payment, $apiKey, $privateKey);
        $delivery = (new CallbackForwarder($http))->deliver($target, $envelope);
        $summary = [
            'payment_id' => $envelope->paymentId,
            'order_id' => $envelope->orderId,
            'destination' => $delivery->url,
            'status' => $delivery->status,
            'success' => $delivery->successful,
        ];

        if ($this->option('json')) {
            $this->writeJsonValue($summary, $apiKey);

            return $delivery->successful ? self::SUCCESS : self::FAILURE;
        }

        if (! $delivery->successful) {
            $status = $delivery->status === null ? 'without an HTTP response' : "with HTTP {$delivery->status}";

            return $this->failure($this->safeTerminalText("Callback delivery failed {$status}.", $apiKey));
        }

        $order = $envelope->orderId === null ? '' : " (order {$envelope->orderId})";
        $message = "Delivered callback for payment {$envelope->paymentId}{$order} to {$delivery->url} with HTTP {$delivery->status}.";
        $this->info($this->safeTerminalText($message, $apiKey));

        return self::SUCCESS;
    }
}

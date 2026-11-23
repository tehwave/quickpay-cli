<?php

namespace App\Callbacks\Watching;

use App\Callbacks\Delivery\CallbackForwarder;
use App\Callbacks\Resolution\PaymentLocator;
use App\Callbacks\Signing\CallbackEnvelopeFactory;
use Closure;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Polls all payments or one selected payment for operations observed after start.
 *
 * The runner is deliberately in-memory: it is a foreground development tool,
 * not a durable webhook relay. Each detected operation receives its own queued
 * immutable envelope. Delivery stays FIFO and retries block later operations,
 * matching Quickpay's same-resource ordering guarantee as closely as polling
 * permits.
 *
 * The runner picks the watch mode and shares one poller and one forwarder
 * between them. The optional hooks replace sleeping, the loop condition and
 * the clock in tests.
 */
final readonly class CallbackWatchRunner implements CallbackWatcher
{
    private SinglePaymentWatch $singlePayment;

    private AccountWideWatch $accountWide;

    public function __construct(
        PaymentLocator $locator,
        CallbackEnvelopeFactory $envelopes,
        CallbackForwarder $forwarder,
        ?callable $sleep = null,
        ?callable $continue = null,
        ?callable $clock = null,
        ?callable $waitUntil = null,
    ) {
        $sleep = Closure::fromCallable($sleep ?? sleep(...));
        $continue = Closure::fromCallable($continue ?? static fn (): bool => true);
        $clock = Closure::fromCallable(
            $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        $waitUntil = Closure::fromCallable($waitUntil ?? static function (DateTimeImmutable $target) use ($clock): void {
            $remaining = (float) $target->format('U.u') - (float) $clock()->format('U.u');

            if ($remaining > 0) {
                usleep((int) ceil($remaining * 1_000_000));
            }
        });

        $poller = new WatchPoller($sleep);
        $operations = new OperationForwarder($envelopes, $forwarder, $sleep);

        $this->singlePayment = new SinglePaymentWatch($locator, $poller, $operations, $sleep, $continue);
        $this->accountWide = new AccountWideWatch($locator, $poller, $operations, $sleep, $continue, $clock, $waitUntil);
    }

    public function run(CallbackWatchRequest $request, CallbackWatchObserver $observer): void
    {
        if ($request->isAccountWide()) {
            $this->accountWide->run($request, $observer);

            return;
        }

        $this->singlePayment->run($request, $observer);
    }
}

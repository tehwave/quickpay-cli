<?php

namespace App\Callbacks\Watching;

use App\Callbacks\Resolution\PaymentLocator;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use UnexpectedValueException;

/**
 * Watches every payment on the account from an explicit readiness time.
 *
 * The session becomes ready at the next whole UTC second and forwards only
 * operations created at or after it. Each poll scans closed whole-second
 * windows that overlap the previous one by a second, so a payment changed at a
 * window edge is not missed; already forwarded operations are remembered per
 * payment.
 */
final readonly class AccountWideWatch
{
    /**
     * @param  Closure(int): mixed  $sleep
     * @param  Closure(): bool  $continue
     * @param  Closure(): DateTimeImmutable  $clock
     * @param  Closure(DateTimeImmutable): mixed  $waitUntil
     */
    public function __construct(
        private PaymentLocator $locator,
        private WatchPoller $poller,
        private OperationForwarder $forwarder,
        private Closure $sleep,
        private Closure $continue,
        private Closure $clock,
        private Closure $waitUntil,
    ) {}

    public function run(CallbackWatchRequest $request, CallbackWatchObserver $observer): void
    {
        $readiness = $this->wholeUtcSecond(($this->clock)())->modify('+1 second');
        ($this->waitUntil)($readiness);
        $observer->watchingAllPayments($readiness);
        $watermark = $readiness;
        $known = [];

        while (($this->continue)()) {
            ($this->sleep)($request->interval);
            $windowEnd = $this->wholeUtcSecond(($this->clock)());

            if ($windowEnd < $watermark) {
                throw new UnexpectedValueException('The callback watcher clock moved before its payment watermark.');
            }

            $payments = $this->poller->poll(
                fn (): array => $this->locator->changedBetween($watermark->modify('-1 second'), $windowEnd),
                $request->interval,
                $observer,
            );
            $watermark = $windowEnd;

            foreach ($payments as $payment) {
                $paymentId = WatchedPayment::id($payment->json);
                $new = array_column(array_filter(
                    WatchedPayment::operations($payment->json),
                    fn (array $operation): bool => $operation['created_at'] >= $readiness
                        && ! isset($known[$paymentId][$operation['id']]),
                ), 'id');

                $this->forwarder->forward($payment, $paymentId, $new, $request, $observer);
                $known[$paymentId] = ($known[$paymentId] ?? []) + array_fill_keys($new, true);
            }
        }
    }

    private function wholeUtcSecond(DateTimeImmutable $time): DateTimeImmutable
    {
        $utc = $time->setTimezone(new DateTimeZone('UTC'));

        return $utc->setTime(
            (int) $utc->format('H'),
            (int) $utc->format('i'),
            (int) $utc->format('s'),
        );
    }
}

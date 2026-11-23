<?php

namespace App\Callbacks\Watching;

use App\Quickpay\Exceptions\QuickpayRequestException;
use Closure;

/**
 * Repeats one read-only Quickpay request until it succeeds or fails terminally.
 *
 * Only polling failures Quickpay marks retryable and network failures are
 * retried; anything else, including merchant access errors, stops the watch.
 */
final readonly class WatchPoller
{
    /** @param Closure(int): mixed $sleep */
    public function __construct(private Closure $sleep) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $request
     * @return T
     */
    public function poll(Closure $request, int $interval, CallbackWatchObserver $observer): mixed
    {
        while (true) {
            try {
                return $request();
            } catch (CallbackPollingException $exception) {
                if (! $exception->retryable) {
                    throw $exception;
                }

                $delay = $exception->retryAfter ?? $interval;
                $observer->pollingRetrying($exception->status, $delay);
                ($this->sleep)($delay);
            } catch (QuickpayRequestException) {
                // QuickpayClient emits this safe generic exception for network
                // failures; GET polling can be retried without mutation risk.
                $observer->pollingRetrying(null, $interval);
                ($this->sleep)($interval);
            }
        }
    }
}

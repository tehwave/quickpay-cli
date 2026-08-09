<?php

namespace App\Callbacks\Delivery;

use App\Callbacks\Signing\CallbackEnvelope;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\HttpClientException;

/**
 * Sends signed callbacks to the explicitly selected development endpoint.
 *
 * This client is intentionally separate from QuickpayClient. Reusing the API
 * client would risk forwarding Basic authentication or Accept-Version headers
 * to an untrusted local/public destination.
 */
final readonly class CallbackForwarder
{
    private const MAX_REDIRECTS = 5;

    public function __construct(private Factory $http) {}

    public function deliver(CallbackTarget $target, CallbackEnvelope $envelope): CallbackDelivery
    {
        $url = $target->url;
        $redirects = 0;
        $visited = [];

        while (true) {
            if (isset($visited[$url])) {
                return new CallbackDelivery($url, null, false, $redirects, CallbackDeliveryFailure::RedirectRejected);
            }

            $visited[$url] = true;

            try {
                // Automatic redirects can silently turn POST into GET. Manual
                // handling preserves the callback method, bytes, and signature.
                $response = $this->http
                    ->withHeaders($envelope->headers)
                    ->withBody($envelope->body, 'application/json')
                    ->withoutRedirecting()
                    ->timeout(30)
                    ->send('POST', $url);
            } catch (HttpClientException) {
                return new CallbackDelivery($url, null, false, $redirects, CallbackDeliveryFailure::Network);
            }

            $status = $response->status();

            if (($status >= 200 && $status < 300) || in_array($status, [302, 303], true)) {
                return new CallbackDelivery($url, $status, true, $redirects);
            }

            if (! in_array($status, [301, 307], true)) {
                return new CallbackDelivery($url, $status, false, $redirects, CallbackDeliveryFailure::HttpResponse);
            }

            $location = $response->header('Location');

            if ($location === '' || $redirects >= self::MAX_REDIRECTS) {
                return new CallbackDelivery($url, null, false, $redirects, CallbackDeliveryFailure::RedirectRejected);
            }

            try {
                $redirectUrl = $this->resolveRedirect($url, $location);

                // A server-selected redirect must not weaken transport
                // protection for a payment payload that started on HTTPS.
                if (parse_url($url, PHP_URL_SCHEME) === 'https'
                    && parse_url($redirectUrl, PHP_URL_SCHEME) === 'http') {
                    return new CallbackDelivery($url, null, false, $redirects, CallbackDeliveryFailure::RedirectRejected);
                }

                $url = CallbackTarget::fromString($redirectUrl)->url;
            } catch (\InvalidArgumentException) {
                return new CallbackDelivery($url, null, false, $redirects, CallbackDeliveryFailure::RedirectRejected);
            }

            $redirects++;
        }
    }

    private function resolveRedirect(string $currentUrl, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $current = parse_url($currentUrl);

        if (! is_array($current) || ! isset($current['scheme'], $current['host'])) {
            return $location;
        }

        $origin = $current['scheme'].'://'.$current['host'];
        if (isset($current['port'])) {
            $origin .= ':'.$current['port'];
        }

        if (str_starts_with($location, '//')) {
            return $current['scheme'].':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = $current['path'] ?? '/';
        $directory = str_ends_with($path, '/') ? $path : dirname($path).'/';

        return $origin.$directory.$location;
    }
}

<?php

namespace App\Quickpay;

use App\Credentials\Exceptions\MerchantAccessException;
use App\Credentials\MerchantAccess;
use App\Quickpay\Exceptions\QuickpayRequestException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\PendingRequest;
use InvalidArgumentException;

/**
 * Owns every HTTP invariant required by the Quickpay API boundary.
 *
 * Callers provide relative paths and business payloads only. Authentication,
 * origin, API version, JSON headers, timeout, and the merchant's write
 * protection remain centralized so raw API access cannot weaken the same guarantees
 * used by first-class commands.
 */
final readonly class QuickpayClient
{
    public const BASE_URL = 'https://api.quickpay.net';

    public const API_VERSION = 'v10';

    public function __construct(
        private Factory $http,
        private string $apiKey,
        private MerchantAccess $access,
    ) {}

    /**
     * Fails before a write request from a read-only merchant.
     *
     * Commands that inspect or confirm before mutating call this first, so a
     * blocked mutation never fetches state or asks for confirmation.
     *
     * @throws MerchantAccessException
     */
    public function authorize(string $method): void
    {
        if (! $this->access->allows($method)) {
            throw MerchantAccessException::blocked($method);
        }
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function get(string $path, array $query = [], array $data = [], array $headers = []): QuickpayResponse
    {
        return $this->request('GET', $path, $query, $data, $headers);
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function post(string $path, array $query = [], array $data = [], array $headers = []): QuickpayResponse
    {
        return $this->request('POST', $path, $query, $data, $headers);
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function put(string $path, array $query = [], array $data = [], array $headers = []): QuickpayResponse
    {
        return $this->request('PUT', $path, $query, $data, $headers);
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    private function request(string $method, string $path, array $query = [], array $data = [], array $headers = []): QuickpayResponse
    {
        $this->authorize($method);

        return $this->send($method, $this->relativePath($path), $query, $data, $headers);
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @param  array<string, string>  $headers
     */
    public function raw(
        string $method,
        string $path,
        array $query = [],
        mixed $data = null,
        array $headers = [],
        bool $hasData = false,
    ): QuickpayResponse {
        $this->authorize($method);

        return $this->send($method, $this->relativePath($path), $query, $data, $headers, $hasData);
    }

    /** Keeps every request on the Quickpay host: absolute and protocol-relative URLs are refused. */
    private function relativePath(string $path): string
    {
        if (parse_url($path, PHP_URL_SCHEME) !== null || str_starts_with($path, '//')) {
            throw new InvalidArgumentException('Quickpay request paths must be relative.');
        }

        return '/'.ltrim($path, '/');
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    private function send(
        string $method,
        string $url,
        array $query = [],
        mixed $data = [],
        array $headers = [],
        bool $hasData = false,
    ): QuickpayResponse {
        $request = $this->pendingRequest($headers);
        $options = [];

        if ($query !== []) {
            $options['query'] = $query;
        }

        if ($data !== [] || $hasData) {
            $options['json'] = $data;
        }

        try {
            // No retry middleware is attached. Replaying capture, refund,
            // cancel, or an arbitrary raw mutation would be unsafe.
            return QuickpayResponse::fromLaravel($request->send(strtoupper($method), $url, $options));
        } catch (HttpClientException) {
            throw new QuickpayRequestException('Unable to connect to Quickpay. Please check your network connection and try again.');
        }
    }

    /** @param array<string, string> $headers */
    private function pendingRequest(array $headers): PendingRequest
    {
        // These headers define the trust boundary and must remain client-owned,
        // including when callers use the flexible raw API command.
        foreach (array_keys($headers) as $name) {
            if (in_array(strtolower($name), ['authorization', 'host', 'accept', 'accept-version', 'content-type'], true)) {
                throw new InvalidArgumentException("The {$name} header cannot be overridden.");
            }
        }

        return $this->http
            ->baseUrl(self::BASE_URL)
            ->withBasicAuth('', $this->apiKey)
            ->acceptJson()
            ->asJson()
            ->withHeaders(['Accept-Version' => self::API_VERSION, ...$headers])
            ->timeout(30);
    }
}

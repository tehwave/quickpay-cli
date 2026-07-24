<?php

namespace App\Quickpay;

use App\Quickpay\Exceptions\QuickpayRequestException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\PendingRequest;
use InvalidArgumentException;

final readonly class QuickpayClient
{
    public const BASE_URL = 'https://api.quickpay.net';

    public const API_VERSION = 'v10';

    public function __construct(
        private Factory $http,
        private string $apiKey,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function get(string $path, array $query = [], array $data = [], array $headers = []): QuickpayResponse
    {
        return $this->request('GET', $path, $query, $data, $headers);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function post(string $path, array $query = [], array $data = [], array $headers = []): QuickpayResponse
    {
        return $this->request('POST', $path, $query, $data, $headers);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function put(string $path, array $query = [], array $data = [], array $headers = []): QuickpayResponse
    {
        return $this->request('PUT', $path, $query, $data, $headers);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function patch(string $path, array $query = [], array $data = [], array $headers = []): QuickpayResponse
    {
        return $this->request('PATCH', $path, $query, $data, $headers);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function delete(string $path, array $query = [], array $data = [], array $headers = []): QuickpayResponse
    {
        return $this->request('DELETE', $path, $query, $data, $headers);
    }

    public function getPagination(string $url): QuickpayResponse
    {
        $parts = parse_url($url);

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'api.quickpay.net'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])) {
            throw new InvalidArgumentException('Pagination URL must use the Quickpay API origin.');
        }

        return $this->send('GET', $url);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function request(string $method, string $path, array $query = [], array $data = [], array $headers = []): QuickpayResponse
    {
        if (parse_url($path, PHP_URL_SCHEME) !== null || str_starts_with($path, '//')) {
            throw new InvalidArgumentException('Quickpay request paths must be relative.');
        }

        $url = '/'.ltrim($path, '/');

        return $this->send($method, $url, $query, $data, $headers);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    private function send(string $method, string $url, array $query = [], array $data = [], array $headers = []): QuickpayResponse
    {
        $request = $this->pendingRequest($headers);
        $options = [];

        if ($query !== []) {
            $options['query'] = $query;
        }

        if ($data !== []) {
            $options['json'] = $data;
        }

        try {
            return QuickpayResponse::fromLaravel($request->send(strtoupper($method), $url, $options));
        } catch (HttpClientException) {
            throw new QuickpayRequestException('Unable to connect to Quickpay. Please check your network connection and try again.');
        }
    }

    /** @param array<string, string> $headers */
    private function pendingRequest(array $headers): PendingRequest
    {
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

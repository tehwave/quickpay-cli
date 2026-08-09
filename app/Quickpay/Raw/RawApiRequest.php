<?php

namespace App\Quickpay\Raw;

use App\Console\Input\KeyValueParser;
use App\Quickpay\QuickpayClient;
use App\Quickpay\QuickpayResponse;
use InvalidArgumentException;
use JsonException;

final readonly class RawApiRequest
{
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @param  array<array-key, mixed>  $query
     * @param  array<string, string>  $headers
     */
    private function __construct(
        public string $method,
        public string $path,
        public array $query,
        public mixed $data,
        public array $headers,
        public bool $hasData,
        public bool $mutation,
    ) {}

    /**
     * @param  array<int, mixed>  $queryOptions
     * @param  array<int, mixed>  $dataOptions
     * @param  array<int, mixed>  $headerOptions
     */
    public static function from(
        mixed $method,
        mixed $path,
        array $queryOptions,
        array $dataOptions,
        mixed $dataJson,
        array $headerOptions,
    ): self {
        $method = strtoupper((string) $method);

        if (! in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException('method must be one of GET, POST, PUT, PATCH, or DELETE.');
        }

        $target = RawApiPath::parse((string) $path);
        $query = array_replace_recursive(
            $target['query'],
            KeyValueParser::parse(self::strings($queryOptions)),
        );
        $headers = RawApiHeaderParser::parse(self::strings($headerOptions));
        [$data, $hasData] = self::data(self::strings($dataOptions), $dataJson);

        return new self(
            $method,
            $target['path'],
            $query,
            $data,
            $headers,
            $hasData,
            $method !== 'GET',
        );
    }

    public function send(QuickpayClient $client): QuickpayResponse
    {
        return $client->raw(
            $this->method,
            $this->path,
            $this->query,
            $this->data,
            $this->headers,
            $this->hasData,
        );
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_filter($values, is_string(...)));
    }

    /**
     * @param  array<int, string>  $options
     * @return array{0: mixed, 1: bool}
     */
    private static function data(array $options, mixed $json): array
    {
        if ($options !== [] && $json !== null) {
            throw new InvalidArgumentException('--data and --data-json are mutually exclusive.');
        }

        if ($json !== null) {
            try {
                $decoded = json_decode((string) $json, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new InvalidArgumentException('--data-json must contain valid JSON.');
            }

            if (! is_array($decoded) && ! is_object($decoded)) {
                throw new InvalidArgumentException('--data-json must decode to a JSON object or array.');
            }

            return [$decoded, true];
        }

        return [KeyValueParser::parse($options), $options !== []];
    }
}

<?php

namespace App\Quickpay;

use Illuminate\Http\Client\Response;

final readonly class QuickpayResponse
{
    /**
     * @param  array<string, array<int, string>>  $headers
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $rawBody,
        public mixed $json,
    ) {}

    public static function fromLaravel(Response $response): self
    {
        $rawBody = $response->body();
        $json = json_validate($rawBody) ? json_decode($rawBody, true) : null;

        return new self($response->status(), $response->headers(), $rawBody, $json);
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $headerName => $values) {
            if (strcasecmp($headerName, $name) === 0) {
                return $values[0] ?? null;
            }
        }

        return null;
    }

    public function errorSummary(): string
    {
        if (! is_array($this->json)) {
            return "Quickpay request failed with HTTP {$this->status}.";
        }

        $parts = [];

        if (isset($this->json['message']) && is_scalar($this->json['message'])) {
            $parts[] = (string) $this->json['message'];
        }

        if (isset($this->json['errors']) && is_array($this->json['errors'])) {
            foreach ($this->json['errors'] as $field => $error) {
                $parts[] = is_string($field)
                    ? "{$field}: ".$this->stringify($error)
                    : $this->stringify($error);
            }
        }

        foreach (['error_code', 'qp_status_code', 'qp_status_msg', 'aq_status_code', 'aq_status_msg'] as $field) {
            if (isset($this->json[$field]) && is_scalar($this->json[$field])) {
                $parts[] = "{$field}: {$this->json[$field]}";
            }
        }

        return $parts === []
            ? "Quickpay request failed with HTTP {$this->status}."
            : implode('; ', $parts);
    }

    private function stringify(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map($this->stringify(...), array_values($value)));
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return 'Unknown error';
    }
}

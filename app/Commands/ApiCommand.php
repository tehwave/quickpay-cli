<?php

namespace App\Commands;

use App\Commands\Concerns\InteractsWithQuickpay;
use App\Credentials\CredentialRedactor;
use App\Credentials\CredentialStore;
use App\Quickpay\QuickpayClient;
use App\Quickpay\QuickpayClientFactory;
use App\Support\KeyValueParser;
use App\Support\RawApiHeaderParser;
use App\Support\RawApiPath;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Console\Output\OutputInterface;

class ApiCommand extends Command
{
    use InteractsWithQuickpay;

    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    protected $signature = 'api {method} {path}
        {--query=*}
        {--data=*}
        {--data-json=}
        {--header=*}
        {--yes}
        {--json}';

    protected $description = 'Send a request to the Quickpay API';

    public function handle(CredentialStore $credentials, QuickpayClientFactory $clients): int
    {
        return $this->withQuickpay($credentials, $clients, function (QuickpayClient $client, string $apiKey): int {
            $method = strtoupper((string) $this->argument('method'));

            if (! in_array($method, self::METHODS, true)) {
                throw new InvalidArgumentException('method must be one of GET, POST, PUT, PATCH, or DELETE.');
            }

            $target = RawApiPath::parse((string) $this->argument('path'));
            $explicitQuery = KeyValueParser::parse($this->stringOptions('query'));
            $query = array_replace_recursive($target['query'], $explicitQuery);
            $headers = RawApiHeaderParser::parse($this->stringOptions('header'));
            [$data, $hasData] = $this->requestData();
            $mutation = $method !== 'GET';

            if ($mutation) {
                $this->writeSafetyLine("Request: {$method} {$target['path']}");

                if (! $this->option('yes')) {
                    if (! $this->input->isInteractive()) {
                        return $this->failure('Non-interactive API mutations require --yes.');
                    }

                    if (! $this->confirmMutation()) {
                        $this->writeSafetyLine('Cancelled.');

                        return self::SUCCESS;
                    }
                }
            }

            $response = $client->raw($method, $target['path'], $query, $data, $headers, $hasData);

            if (! $response->successful()) {
                return $this->responseFailure($response, $apiKey);
            }

            if ($this->option('json')) {
                $this->getOutput()->write(CredentialRedactor::redact($response->rawBody, $apiKey));

                return self::SUCCESS;
            }

            if ($response->json !== null) {
                $json = json_encode(
                    $response->json,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                );
                $this->getOutput()->writeln(CredentialRedactor::redact($json, $apiKey), OutputInterface::OUTPUT_RAW);

                return self::SUCCESS;
            }

            $this->getOutput()->writeln(
                CredentialRedactor::redact($response->rawBody, $apiKey),
                OutputInterface::OUTPUT_RAW,
            );

            return self::SUCCESS;
        });
    }

    /** @return array{0: mixed, 1: bool} */
    private function requestData(): array
    {
        $dataOptions = $this->stringOptions('data');
        $json = $this->option('data-json');

        if ($dataOptions !== [] && $json !== null) {
            throw new InvalidArgumentException('--data and --data-json are mutually exclusive.');
        }

        if ($json !== null) {
            try {
                $decoded = json_decode($json, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new InvalidArgumentException('--data-json must contain valid JSON.');
            }

            if (! is_array($decoded) && ! is_object($decoded)) {
                throw new InvalidArgumentException('--data-json must decode to a JSON object or array.');
            }

            return [$decoded, true];
        }

        return [KeyValueParser::parse($dataOptions), $dataOptions !== []];
    }

    /** @return array<int, string> */
    private function stringOptions(string $name): array
    {
        $values = $this->option($name);
        $strings = [];

        foreach (is_array($values) ? $values : [] as $value) {
            if (is_string($value)) {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    private function writeSafetyLine(string $message): void
    {
        $output = $this->option('json')
            ? $this->getOutput()->getErrorStyle()
            : $this->getOutput();

        $output->writeln($message, OutputInterface::OUTPUT_RAW);
    }

    private function confirmMutation(): bool
    {
        if (! $this->option('json')) {
            return $this->confirm('Continue?');
        }

        $output = $this->output;
        $this->output = new OutputStyle($this->input, $output->getErrorStyle());

        try {
            return $this->confirm('Continue?');
        } finally {
            $this->output = $output;
        }
    }
}

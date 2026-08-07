<?php

namespace App\Commands\Api;

use App\Console\AuthenticatedCommand;
use App\Console\Confirmation\MutationConfirmation;
use App\Console\Output\ResponseBodySanitizer;
use App\Quickpay\AuthenticatedQuickpay;
use App\Quickpay\AuthenticatedQuickpayFactory;
use App\Quickpay\Raw\RawApiRequest;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Provides guarded access to Quickpay endpoints without a first-class command.
 *
 * Flexibility stops at the HTTP trust boundary: path, protected headers, body,
 * and mutation confirmation still pass through the same validation as the
 * dedicated payment commands.
 */
class ApiRequestCommand extends AuthenticatedCommand
{
    protected $signature = 'api {method} {path}
        {--query=*}
        {--data=*}
        {--data-json=}
        {--header=*}
        {--yes}
        {--json}';

    protected $description = 'Send a request to the Quickpay API';

    public function handle(
        AuthenticatedQuickpayFactory $quickpay,
        MutationConfirmation $confirmation,
    ): int {
        return $this->withQuickpay($quickpay, function (AuthenticatedQuickpay $authenticated) use ($confirmation): int {
            $apiKey = $authenticated->apiKey->value();
            $request = RawApiRequest::from(
                $this->argument('method'),
                $this->argument('path'),
                (array) $this->option('query'),
                (array) $this->option('data'),
                $this->option('data-json'),
                (array) $this->option('header'),
            );

            if ($request->mutation) {
                $this->writeSafetyLine("Quickpay API request: {$request->method}");

                if (! $confirmation->approve(
                    preapproved: (bool) $this->option('yes'),
                    interactive: $this->input->isInteractive(),
                    ask: fn (): bool => $this->confirmMutation(),
                    nonInteractiveMessage: 'Non-interactive API mutations require --yes.',
                )) {
                    $this->writeSafetyLine('Cancelled.');

                    return self::SUCCESS;
                }
            }

            $response = $request->send($authenticated->client);

            if (! $response->successful()) {
                return $this->responseFailure($response, $apiKey);
            }

            if ($this->option('json')) {
                if ($response->rawBody === '') {
                    $this->getOutput()->write('null');

                    return self::SUCCESS;
                }

                $this->getOutput()->write(ResponseBodySanitizer::json($response->rawBody, $apiKey));

                return self::SUCCESS;
            }

            if (json_validate($response->rawBody)) {
                $safeJson = ResponseBodySanitizer::json($response->rawBody, $apiKey);
                $json = json_encode(
                    json_decode($safeJson, flags: JSON_THROW_ON_ERROR),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                );
                $this->getOutput()->writeln($json, OutputInterface::OUTPUT_RAW);

                return self::SUCCESS;
            }

            $this->getOutput()->writeln(
                ResponseBodySanitizer::terminalText($response->rawBody, $apiKey),
                OutputInterface::OUTPUT_RAW,
            );

            return self::SUCCESS;
        });
    }

    private function writeSafetyLine(string $message): void
    {
        // Keep stdout machine-readable in JSON mode without hiding mutation
        // summaries and confirmation prompts from the operator.
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
